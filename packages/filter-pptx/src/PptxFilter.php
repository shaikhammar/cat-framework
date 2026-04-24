<?php

declare(strict_types=1);

namespace CatFramework\FilterPptx;

use CatFramework\Core\Contract\FileFilterInterface;
use CatFramework\Core\Exception\FilterException;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Core\Util\MergedRun;
use CatFramework\Core\Util\OoxmlRunMerger;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

class PptxFilter implements FileFilterInterface
{
    private const string A_NS   = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const string P_NS   = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const string XML_NS = 'http://www.w3.org/XML/1998/namespace';

    private const array RTL_PREFIXES = ['ar', 'he', 'fa', 'ur', 'yi', 'dv', 'ps', 'sd'];

    public function supports(string $filePath, ?string $mimeType = null): bool
    {
        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'pptx') {
            return true;
        }
        return $mimeType === 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    }

    public function getSupportedExtensions(): array
    {
        return ['.pptx'];
    }

    // ──────────────────────────────────────────────────────────── extract ──

    public function extract(string $filePath, string $sourceLanguage, string $targetLanguage): BilingualDocument
    {
        if (!file_exists($filePath)) {
            throw new FilterException("File not found: {$filePath}");
        }

        $skeletonPath = sys_get_temp_dir() . '/cat-' . uniqid() . '.skl';
        if (!copy($filePath, $skeletonPath)) {
            throw new FilterException("Cannot create skeleton file for: {$filePath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($skeletonPath) !== true) {
            @unlink($skeletonPath);
            throw new FilterException("Cannot open PPTX file: {$filePath}");
        }

        $translatableFiles = $this->findTranslatableFiles($zip);

        $seqNo    = 1;
        $allPairs = [];

        foreach ($translatableFiles as ['path' => $xmlFile, 'source' => $source]) {
            $content = $zip->getFromName($xmlFile);
            if ($content === false) {
                continue;
            }

            $dom   = $this->loadXml($content);
            $xpath = $this->makeXPath($dom);

            // Snapshot before mutating — DOMNodeList is live.
            $paragraphs     = iterator_to_array($xpath->query('//a:p'));
            $itemsToProcess = [];

            foreach ($paragraphs as $para) {
                $rawRuns = $this->extractRuns($para, $xpath, $dom);
                if (!empty($rawRuns)) {
                    $mergedRuns = OoxmlRunMerger::merge($rawRuns);
                    $itemsToProcess[] = ['para' => $para, 'runs' => $mergedRuns];
                }
            }

            foreach ($itemsToProcess as $item) {
                $placeholder = sprintf('{{SEG:%03d}}', $seqNo);
                $baseRpr     = OoxmlRunMerger::findBaseRpr($item['runs']);
                $elements    = OoxmlRunMerger::buildSegmentElements($item['runs'], $baseRpr);

                $this->replaceParagraphWithPlaceholder($item['para'], $dom, $placeholder);

                $allPairs[] = new SegmentPair(
                    source: new Segment('seg-' . $seqNo, $elements),
                    context: [
                        'file'     => $xmlFile,
                        'seq'      => $seqNo,
                        'base_rpr' => $baseRpr,
                        'source'   => $source,
                    ],
                );

                $seqNo++;
            }

            $zip->addFromString($xmlFile, $dom->saveXML());
        }

        $zip->close();

        $document = new BilingualDocument(
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            originalFile: basename($filePath),
            mimeType: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            skeleton: ['path' => $skeletonPath],
        );

        foreach ($allPairs as $pair) {
            $document->addSegmentPair($pair);
        }

        return $document;
    }

    // ──────────────────────────────────────────────────────────── rebuild ──

    public function rebuild(BilingualDocument $document, string $outputPath): void
    {
        $skeletonPath = $document->skeleton['path'] ?? null;
        if ($skeletonPath === null || !file_exists($skeletonPath)) {
            throw new FilterException('Skeleton file not found. The document must be extracted by this filter before rebuild.');
        }

        if (!copy($skeletonPath, $outputPath)) {
            throw new FilterException("Cannot write output file: {$outputPath}");
        }

        $isRtl = $this->isRtlLanguage($document->targetLanguage);

        $pairsBySeq = [];
        foreach ($document->getSegmentPairs() as $pair) {
            $pairsBySeq[$pair->context['seq']] = $pair;
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            throw new FilterException("Cannot open output file for rebuild: {$outputPath}");
        }

        foreach ($this->findTranslatableFiles($zip) as ['path' => $xmlFile]) {
            $content = $zip->getFromName($xmlFile);
            if ($content === false) {
                continue;
            }

            $dom   = $this->loadXml($content);
            $xpath = $this->makeXPath($dom);

            $placeholders = [];
            foreach (iterator_to_array($xpath->query('//a:t')) as $tNode) {
                if (preg_match('/^\{\{SEG:(\d+)\}\}$/', $tNode->textContent, $m)) {
                    $placeholders[] = ['t' => $tNode, 'seq' => (int) $m[1]];
                }
            }

            foreach ($placeholders as $item) {
                $pair = $pairsBySeq[$item['seq']] ?? null;
                if ($pair === null) {
                    continue;
                }

                $tNode          = $item['t'];
                $placeholderRun = $tNode->parentNode; // <a:r>
                $para           = $placeholderRun->parentNode; // <a:p>

                $segment = $pair->target ?? $pair->source;
                $baseRpr = $pair->context['base_rpr'] ?? '';

                $runs = $this->buildRunsFromSegment($segment, $baseRpr, $dom);

                if ($isRtl) {
                    $this->addRtlToRuns($runs, $dom);
                    $this->addBidiToParagraph($para, $dom, $xpath);
                }

                foreach ($runs as $run) {
                    $para->insertBefore($run, $placeholderRun);
                }
                $para->removeChild($placeholderRun);
            }

            $zip->addFromString($xmlFile, $dom->saveXML());
        }

        $zip->close();
    }

    // ─────────────────────────────────────────────── extraction helpers ──

    /**
     * Returns an ordered list of ['path' => string, 'source' => 'slide'|'notes']
     * entries for every XML file in the ZIP that contains translatable text.
     *
     * Slide order comes from ppt/_rels/presentation.xml.rels, which preserves
     * the authoring application's declared order (ZIP entry order is unreliable).
     * Each slide's own _rels file links to its notes slide, if any.
     * Hidden slides (show="0" on <p:sld>) are silently skipped.
     *
     * @return array<int, array{path: string, source: 'slide'|'notes'}>
     */
    private function findTranslatableFiles(ZipArchive $zip): array
    {
        $RELS_NS    = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $SLIDE_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide';
        $NOTES_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide';

        $presRels = $zip->getFromName('ppt/_rels/presentation.xml.rels');
        if ($presRels === false) {
            return [];
        }

        $result = [];

        foreach ($this->parseRelationshipTargets($presRels, $RELS_NS, $SLIDE_TYPE, 'ppt/') as $slidePath) {
            if ($this->isHiddenSlide($zip, $slidePath)) {
                continue;
            }

            $result[] = ['path' => $slidePath, 'source' => 'slide'];

            // Resolve notes slide via this slide's own _rels file.
            $dir      = dirname($slidePath);
            $relsPath = "{$dir}/_rels/" . basename($slidePath) . '.rels';
            $slideRels = $zip->getFromName($relsPath);
            if ($slideRels === false) {
                continue;
            }

            foreach ($this->parseRelationshipTargets($slideRels, $RELS_NS, $NOTES_TYPE, $dir . '/') as $notesPath) {
                if ($zip->locateName($notesPath) !== false) {
                    $result[] = ['path' => $notesPath, 'source' => 'notes'];
                }
            }
        }

        return $result;
    }

    /**
     * Parse a .rels XML string and return resolved ZIP paths for all relationships
     * of the given $type. $baseDir is prepended when resolving relative targets.
     *
     * @return string[]
     */
    private function parseRelationshipTargets(string $xml, string $relsNs, string $type, string $baseDir): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml);
        libxml_clear_errors();
        if (!$ok) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('r', $relsNs);

        $paths = [];
        foreach ($xpath->query('//r:Relationship[@Type="' . $type . '"]') as $rel) {
            /** @var DOMElement $rel */
            $paths[] = $this->resolveRelativePath($baseDir, $rel->getAttribute('Target'));
        }

        return $paths;
    }

    /**
     * Resolve $relative against $baseDir, collapsing ".." segments.
     * All paths use forward slashes (ZIP entry convention).
     */
    private function resolveRelativePath(string $baseDir, string $relative): string
    {
        if (str_starts_with($relative, '/')) {
            return ltrim($relative, '/');
        }

        $parts    = explode('/', rtrim($baseDir, '/') . '/' . $relative);
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($resolved);
            } elseif ($part !== '' && $part !== '.') {
                $resolved[] = $part;
            }
        }

        return implode('/', $resolved);
    }

    /**
     * Returns true if the slide XML's root element carries show="0",
     * marking the slide as hidden in the presentation.
     */
    private function isHiddenSlide(ZipArchive $zip, string $slidePath): bool
    {
        $content = $zip->getFromName($slidePath);
        if ($content === false) {
            return false;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($content, LIBXML_COMPACT | LIBXML_NONET);
        libxml_clear_errors();

        $root = $dom->documentElement;
        return $root !== null && $root->getAttribute('show') === '0';
    }

    /**
     * Extracts text runs from a single <a:p> paragraph element.
     * Returns [] for paragraphs containing only whitespace.
     *
     * @return MergedRun[]
     */
    private function extractRuns(DOMElement $para, DOMXPath $xpath, DOMDocument $dom): array
    {
        $rawRuns = [];

        foreach (iterator_to_array($xpath->query('.//a:r', $para)) as $run) {
            $tNodes = $xpath->query('a:t', $run);
            if ($tNodes->length === 0) {
                continue;
            }

            $text = '';
            foreach ($tNodes as $t) {
                $text .= $t->textContent;
            }

            if ($text === '') {
                continue;
            }

            $rPr    = $xpath->query('a:rPr', $run)->item(0);
            $rPrXml = ($rPr instanceof DOMElement) ? $dom->saveXML($rPr) : '';

            $rawRuns[] = new MergedRun($rPrXml, $text);
        }

        if (empty($rawRuns)) {
            return [];
        }

        $plainText = implode('', array_map(fn(MergedRun $r) => $r->text, $rawRuns));
        if (trim($plainText) === '') {
            return [];
        }

        return $rawRuns;
    }

    /**
     * Removes all translatable content from <a:p> and inserts a single
     * placeholder run. Preserves <a:pPr> (alignment, spacing, indents).
     */
    private function replaceParagraphWithPlaceholder(DOMElement $para, DOMDocument $dom, string $placeholder): void
    {
        $toRemove = [];
        foreach ($para->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'pPr') {
                continue;
            }
            $toRemove[] = $child;
        }
        foreach ($toRemove as $node) {
            $para->removeChild($node);
        }

        $run = $dom->createElementNS(self::A_NS, 'a:r');
        $t   = $dom->createElementNS(self::A_NS, 'a:t');
        $t->appendChild($dom->createTextNode($placeholder));
        $run->appendChild($t);
        $para->appendChild($run);
    }

    // ──────────────────────────────────────────────── rebuild helpers ──

    /** @return DOMElement[] */
    private function buildRunsFromSegment(Segment $segment, string $baseRpr, DOMDocument $dom): array
    {
        $mergedRuns = OoxmlRunMerger::segmentToRuns($segment, $baseRpr);
        return array_map(fn(MergedRun $r) => $this->createRun($dom, $r->text, $r->rPrXml), $mergedRuns);
    }

    private function createRun(DOMDocument $dom, string $text, string $rPrXml): DOMElement
    {
        $run = $dom->createElementNS(self::A_NS, 'a:r');

        if ($rPrXml !== '') {
            $temp = new DOMDocument();
            libxml_use_internal_errors(true);
            $temp->loadXML($rPrXml);
            libxml_clear_errors();

            if ($temp->documentElement !== null) {
                $run->appendChild($dom->importNode($temp->documentElement, deep: true));
            }
        }

        $t = $dom->createElementNS(self::A_NS, 'a:t');
        $t->appendChild($dom->createTextNode($text));

        if ($text !== trim($text)) {
            $t->setAttributeNS(self::XML_NS, 'xml:space', 'preserve');
        }

        $run->appendChild($t);

        return $run;
    }

    // ───────────────────────────────────────────────────── RTL support ──

    /**
     * Sets rtl="1" on each run's <a:rPr> (creating the element if absent).
     * DrawingML uses an XML attribute for RTL, unlike DOCX's <w:rtl/> element.
     *
     * @param DOMElement[] $runs
     */
    private function addRtlToRuns(array $runs, DOMDocument $dom): void
    {
        foreach ($runs as $run) {
            $rPr = null;
            foreach ($run->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'rPr') {
                    $rPr = $child;
                    break;
                }
            }

            if ($rPr === null) {
                $rPr = $dom->createElementNS(self::A_NS, 'a:rPr');
                $run->insertBefore($rPr, $run->firstChild);
            }

            $rPr->setAttribute('rtl', '1');
        }
    }

    /** Sets rtl="1" on <a:pPr> for the paragraph (creating it if absent). */
    private function addBidiToParagraph(DOMElement $para, DOMDocument $dom, DOMXPath $xpath): void
    {
        $pPr = $xpath->query('a:pPr', $para)->item(0);

        if ($pPr === null) {
            $pPr = $dom->createElementNS(self::A_NS, 'a:pPr');
            $para->insertBefore($pPr, $para->firstChild);
        }

        $pPr->setAttribute('rtl', '1');
    }

    private function isRtlLanguage(string $langTag): bool
    {
        return in_array(strtolower(explode('-', $langTag)[0]), self::RTL_PREFIXES, true);
    }

    // ─────────────────────────────────────────────────── DOM utilities ──

    private function loadXml(string $content): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $ok     = $dom->loadXML($content, LIBXML_COMPACT | LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if (!$ok) {
            $message = $errors ? $errors[0]->message : 'unknown parse error';
            throw new FilterException("Cannot parse XML: {$message}");
        }

        return $dom;
    }

    private function makeXPath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('a', self::A_NS);
        $xpath->registerNamespace('p', self::P_NS);
        return $xpath;
    }
}
