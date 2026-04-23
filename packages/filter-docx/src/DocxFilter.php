<?php

declare(strict_types=1);

namespace CatFramework\FilterDocx;

use CatFramework\Core\Contract\FileFilterInterface;
use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Exception\FilterException;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Core\Util\MergedRun;
use CatFramework\Core\Util\OoxmlRunMerger;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

class DocxFilter implements FileFilterInterface
{
    private const string W_NS  = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const string XML_NS = 'http://www.w3.org/XML/1998/namespace';

    private const array RTL_PREFIXES = ['ar', 'he', 'fa', 'ur', 'yi', 'dv', 'ps', 'sd'];

    public function supports(string $filePath, ?string $mimeType = null): bool
    {
        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'docx') {
            return true;
        }
        return $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    public function getSupportedExtensions(): array
    {
        return ['.docx'];
    }

    // ──────────────────────────────────────────────────────────── extract ──

    public function extract(string $filePath, string $sourceLanguage, string $targetLanguage): BilingualDocument
    {
        if (!file_exists($filePath)) {
            throw new FilterException("File not found: {$filePath}");
        }

        // The skeleton is a copy of the original ZIP with paragraph text replaced
        // by placeholders. It must survive between extract() and rebuild() calls.
        $skeletonPath = sys_get_temp_dir() . '/cat-' . uniqid() . '.skl';
        if (!copy($filePath, $skeletonPath)) {
            throw new FilterException("Cannot create skeleton file for: {$filePath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($skeletonPath) !== true) {
            @unlink($skeletonPath);
            throw new FilterException("Cannot open DOCX file: {$filePath}");
        }

        $translatableFiles = $this->findTranslatableFiles($zip);

        $seqNo    = 1;
        $allPairs = [];

        foreach ($translatableFiles as $xmlFile) {
            $content = $zip->getFromName($xmlFile);
            if ($content === false) {
                continue;
            }

            $dom   = $this->loadXml($content);
            $xpath = $this->makeXPath($dom);

            // Collect paragraphs first, then mutate the DOM.
            // DOMNodeList iteration is live; snapshot before modifying.
            $paragraphs      = iterator_to_array($xpath->query('//w:p'));
            $itemsToProcess  = [];

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
            mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
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

        // Index segment pairs by their sequential number for O(1) lookup.
        $pairsBySeq = [];
        foreach ($document->getSegmentPairs() as $pair) {
            $pairsBySeq[$pair->context['seq']] = $pair;
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            throw new FilterException("Cannot open output file for rebuild: {$outputPath}");
        }

        $translatableFiles = $this->findTranslatableFiles($zip);

        foreach ($translatableFiles as $xmlFile) {
            $content = $zip->getFromName($xmlFile);
            if ($content === false) {
                continue;
            }

            $dom   = $this->loadXml($content);
            $xpath = $this->makeXPath($dom);

            // Find every placeholder and build a list before mutating the DOM.
            $placeholders = [];
            foreach (iterator_to_array($xpath->query('//w:t')) as $tNode) {
                if (preg_match('/^\{\{SEG:(\d+)\}\}$/', $tNode->textContent, $m)) {
                    $placeholders[] = ['t' => $tNode, 'seq' => (int) $m[1]];
                }
            }

            foreach ($placeholders as $item) {
                $seq  = $item['seq'];
                $pair = $pairsBySeq[$seq] ?? null;
                if ($pair === null) {
                    continue;
                }

                $tNode          = $item['t'];
                $placeholderRun = $tNode->parentNode; // <w:r> containing {{SEG:NNN}}
                $para           = $placeholderRun->parentNode; // <w:p>

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

    /** @return string[] Ordered list of XML paths to extract from the ZIP. */
    private function findTranslatableFiles(ZipArchive $zip): array
    {
        $files = ['word/document.xml'];

        foreach (['word/header', 'word/footer'] as $prefix) {
            for ($i = 1; $i <= 10; $i++) {
                $path = "{$prefix}{$i}.xml";
                if ($zip->locateName($path) !== false) {
                    $files[] = $path;
                }
            }
        }

        foreach (['word/footnotes.xml', 'word/endnotes.xml'] as $path) {
            if ($zip->locateName($path) !== false) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * Extracts raw text runs from a <w:p> element. Returns an empty array for
     * paragraphs with no meaningful text. Merging is handled by OoxmlRunMerger.
     *
     * @return MergedRun[]
     */
    private function extractRuns(DOMElement $para, DOMXPath $xpath, DOMDocument $dom): array
    {
        $rawRuns = [];

        foreach (iterator_to_array($xpath->query('.//w:r', $para)) as $run) {
            $tNodes = $xpath->query('w:t', $run);
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

            $rPr    = $xpath->query('w:rPr', $run)->item(0);
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
     * Removes all translatable content from a <w:p> and inserts a single
     * placeholder run. Preserves <w:pPr> (paragraph properties: alignment,
     * style, indent) because these belong to the paragraph structure, not the
     * translatable text.
     */
    private function replaceParagraphWithPlaceholder(DOMElement $para, DOMDocument $dom, string $placeholder): void
    {
        $toRemove = [];
        foreach ($para->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'pPr') {
                continue; // preserve paragraph formatting
            }
            $toRemove[] = $child;
        }
        foreach ($toRemove as $node) {
            $para->removeChild($node);
        }

        $run = $dom->createElementNS(self::W_NS, 'w:r');
        $t   = $dom->createElementNS(self::W_NS, 'w:t');
        $t->appendChild($dom->createTextNode($placeholder));
        $run->appendChild($t);
        $para->appendChild($run);
    }

    // ──────────────────────────────────────────────── rebuild helpers ──

    /**
     * Converts a Segment back into an ordered list of OOXML <w:r> DOMElements.
     *
     * Walk $segment->getElements() in order:
     *   - Plain string: accumulate text under the current formatting.
     *   - InlineCode OPENING: flush any accumulated text as a run, then switch
     *     the current formatting to $code->data (the rPr XML stored at extract).
     *   - InlineCode CLOSING: flush accumulated text as a run, then reset
     *     current formatting back to $baseRpr.
     *   - InlineCode STANDALONE: not handled in Phase 2; skip.
     *
     * Use $this->createRun($dom, $text, $rPrXml) to create each <w:r> element.
     *
     * @param Segment     $segment The target segment, or source as fallback.
     * @param string      $baseRpr rPr XML for text NOT wrapped in InlineCodes.
     *                             Empty string means no explicit run properties.
     * @param DOMDocument $dom     The document to create nodes in.
     * @return DOMElement[]
     */
    private function buildRunsFromSegment(Segment $segment, string $baseRpr, DOMDocument $dom): array
    {
        $mergedRuns = OoxmlRunMerger::segmentToRuns($segment, $baseRpr);
        return array_map(fn(MergedRun $r) => $this->createRun($dom, $r->text, $r->rPrXml), $mergedRuns);
    }

    /**
     * Creates a single OOXML <w:r> element containing the given text.
     * Adds xml:space="preserve" to <w:t> when the text has leading or
     * trailing whitespace, matching Word's own serialization behaviour.
     *
     * @param string $rPrXml Serialized <w:rPr>…</w:rPr>, or '' for no formatting.
     */
    private function createRun(DOMDocument $dom, string $text, string $rPrXml): DOMElement
    {
        $run = $dom->createElementNS(self::W_NS, 'w:r');

        if ($rPrXml !== '') {
            $temp = new DOMDocument();
            libxml_use_internal_errors(true);
            // rPrXml from saveXML($node) includes the namespace declaration, so it
            // parses as a standalone fragment without needing outer context.
            $temp->loadXML($rPrXml);
            libxml_clear_errors();

            if ($temp->documentElement !== null) {
                $run->appendChild($dom->importNode($temp->documentElement, deep: true));
            }
        }

        $t = $dom->createElementNS(self::W_NS, 'w:t');
        $t->appendChild($dom->createTextNode($text));

        if ($text !== trim($text)) {
            $t->setAttributeNS(self::XML_NS, 'xml:space', 'preserve');
        }

        $run->appendChild($t);

        return $run;
    }

    // ───────────────────────────────────────────────────── RTL support ──

    /**
     * Injects <w:rtl/> into the <w:rPr> of each run.
     * Required for Urdu / Arabic target text to render in the correct direction.
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
                $rPr = $dom->createElementNS(self::W_NS, 'w:rPr');
                $run->insertBefore($rPr, $run->firstChild);
            }

            $hasRtl = false;
            foreach ($rPr->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'rtl') {
                    $hasRtl = true;
                    break;
                }
            }

            if (!$hasRtl) {
                $rPr->appendChild($dom->createElementNS(self::W_NS, 'w:rtl'));
            }
        }
    }

    /** Injects <w:bidi/> into the <w:pPr> of a paragraph for RTL target text. */
    private function addBidiToParagraph(DOMElement $para, DOMDocument $dom, DOMXPath $xpath): void
    {
        $pPr = $xpath->query('w:pPr', $para)->item(0);

        if ($pPr === null) {
            $pPr = $dom->createElementNS(self::W_NS, 'w:pPr');
            $para->insertBefore($pPr, $para->firstChild);
        }

        if ($xpath->query('w:bidi', $pPr)->length === 0) {
            $pPr->appendChild($dom->createElementNS(self::W_NS, 'w:bidi'));
        }
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
        $ok = $dom->loadXML($content, LIBXML_COMPACT | LIBXML_NONET);
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
        $xpath->registerNamespace('w', self::W_NS);
        return $xpath;
    }
}
