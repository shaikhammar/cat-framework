<?php

declare(strict_types=1);

namespace CatFramework\FilterXlsx;

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

class XlsxFilter implements FileFilterInterface
{
    /** Spreadsheet ML namespace used in all XLSX XML files. */
    private const string SS_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** Heuristic: skip shared strings that look like numbers, currencies, or percentages. */
    private const string NUMERIC_PATTERN = '/^\s*[\d.,\-+\s%$€£¥₹]+\s*$/u';

    public function supports(string $filePath, ?string $mimeType = null): bool
    {
        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'xlsx') {
            return true;
        }
        return $mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function getSupportedExtensions(): array
    {
        return ['.xlsx'];
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
            throw new FilterException("Cannot open XLSX file: {$filePath}");
        }

        // ── 1. Parse shared strings ──────────────────────────────────────
        $sharedStrings = $this->parseSharedStrings($zip);

        // ── 2. Find which shared string indices are actually used in cells ──
        $usedIndices = $this->collectUsedSharedStringIndices($zip);

        // ── 3. Collect inline strings from worksheets ────────────────────
        $inlineStrings = $this->collectInlineStrings($zip);

        // ── 4. Build SegmentPairs for translatable shared strings ─────────
        $seqNo    = 1;
        $allPairs = [];
        $seqByIndex = []; // shared string index → seq number

        foreach ($usedIndices as $idx) {
            if (!isset($sharedStrings[$idx])) {
                continue;
            }
            ['runs' => $runs, 'plain' => $plain] = $sharedStrings[$idx];

            if ($this->isNonTranslatable($plain)) {
                continue;
            }

            $baseRpr  = OoxmlRunMerger::findBaseRpr($runs);
            $elements = OoxmlRunMerger::buildSegmentElements($runs, $baseRpr);
            $seqByIndex[$idx] = $seqNo;

            $allPairs[] = new SegmentPair(
                source: new Segment('seg-' . $seqNo, $elements),
                context: [
                    'type'     => 'shared',
                    'ss_index' => $idx,
                    'seq'      => $seqNo,
                    'base_rpr' => $baseRpr,
                ],
            );

            $seqNo++;
        }

        // ── 5. Build SegmentPairs for inline strings ──────────────────────
        foreach ($inlineStrings as $inline) {
            if ($this->isNonTranslatable($inline['text'])) {
                continue;
            }

            $allPairs[] = new SegmentPair(
                source: new Segment('seg-' . $seqNo, [$inline['text']]),
                context: [
                    'type'    => 'inline',
                    'file'    => $inline['file'],
                    'cell'    => $inline['cell'],
                    'seq'     => $seqNo,
                    'base_rpr' => '',
                ],
            );

            $seqNo++;
        }

        // ── 6. Write skeleton: replace translatable <si> with placeholders ──
        $this->writeSharedStringsSkeleton($zip, $sharedStrings, $seqByIndex);
        $this->writeInlineStringSkeleton($zip, $allPairs);

        $zip->close();

        $document = new BilingualDocument(
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            originalFile: basename($filePath),
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
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

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) {
            throw new FilterException("Cannot open output file for rebuild: {$outputPath}");
        }

        // Index pairs by seq number for O(1) lookup
        $pairsBySeq = [];
        foreach ($document->getSegmentPairs() as $pair) {
            $pairsBySeq[$pair->context['seq']] = $pair;
        }

        // ── Rebuild sharedStrings.xml ────────────────────────────────────
        $ssContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssContent !== false) {
            $dom   = $this->loadXml($ssContent);
            $xpath = $this->makeXPath($dom);

            foreach (iterator_to_array($xpath->query('//x:si/x:t')) as $tNode) {
                if (!preg_match('/^\{\{SEG:(\d+)\}\}$/', $tNode->textContent, $m)) {
                    continue;
                }
                $seq  = (int) $m[1];
                $pair = $pairsBySeq[$seq] ?? null;
                if ($pair === null) {
                    continue;
                }

                $segment = $pair->target ?? $pair->source;
                $baseRpr = $pair->context['base_rpr'] ?? '';
                $si      = $tNode->parentNode; // <t> direct child of <si>

                $this->replaceSimpleSiWithSegment($si, $segment, $baseRpr, $dom);
            }

            $zip->addFromString('xl/sharedStrings.xml', $dom->saveXML());
        }

        // ── Rebuild inline strings in worksheets ──────────────────────────
        $sheetFiles = $this->findWorksheetFiles($zip);
        foreach ($sheetFiles as $sheetFile) {
            $content = $zip->getFromName($sheetFile);
            if ($content === false) {
                continue;
            }

            $dom     = $this->loadXml($content);
            $xpath   = $this->makeXPath($dom);
            $changed = false;

            foreach (iterator_to_array($xpath->query('//x:c[@t="inlineStr"]/x:is/x:t')) as $tNode) {
                if (!preg_match('/^\{\{SEG:(\d+)\}\}$/', $tNode->textContent, $m)) {
                    continue;
                }
                $seq  = (int) $m[1];
                $pair = $pairsBySeq[$seq] ?? null;
                if ($pair === null) {
                    continue;
                }

                $segment = $pair->target ?? $pair->source;
                $tNode->nodeValue = $segment->getPlainText();
                $changed = true;
            }

            if ($changed) {
                $zip->addFromString($sheetFile, $dom->saveXML());
            }
        }

        // ── Delete calcChain.xml — Excel regenerates it on open ───────────
        if ($zip->locateName('xl/calcChain.xml') !== false) {
            $zip->deleteName('xl/calcChain.xml');
        }

        $zip->close();
    }

    // ──────────────────────────────────────────── shared string parsing ──

    /**
     * Parse xl/sharedStrings.xml and return all <si> entries.
     *
     * @return array<int, array{runs: MergedRun[], plain: string}>
     */
    private function parseSharedStrings(ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if ($content === false) {
            return [];
        }

        $dom   = $this->loadXml($content);
        $xpath = $this->makeXPath($dom);

        $result = [];
        $index  = 0;

        foreach (iterator_to_array($xpath->query('//x:sst/x:si')) as $si) {
            /** @var DOMElement $si */
            $result[$index] = $this->parseSi($si, $xpath, $dom);
            $index++;
        }

        return $result;
    }

    /**
     * Parse a single <si> element into MergedRuns.
     * Handles both plain-text <si><t>text</t></si> and rich-text <si><r>…</r></si>.
     *
     * @return array{runs: MergedRun[], plain: string}
     */
    private function parseSi(DOMElement $si, DOMXPath $xpath, DOMDocument $dom): array
    {
        $rNodes = $xpath->query('x:r', $si);

        if ($rNodes->length > 0) {
            // Rich text: one or more <r> sub-elements
            $rawRuns = [];
            foreach ($rNodes as $r) {
                $tNode = $xpath->query('x:t', $r)->item(0);
                if ($tNode === null || $tNode->textContent === '') {
                    continue;
                }
                $rPr    = $xpath->query('x:rPr', $r)->item(0);
                $rPrXml = ($rPr instanceof DOMElement) ? $dom->saveXML($rPr) : '';
                $rawRuns[] = new MergedRun($rPrXml, $tNode->textContent);
            }

            if (empty($rawRuns)) {
                return ['runs' => [new MergedRun('', '')], 'plain' => ''];
            }

            $merged = OoxmlRunMerger::merge($rawRuns);
            $plain  = implode('', array_map(fn(MergedRun $r) => $r->text, $merged));
            return ['runs' => $merged, 'plain' => $plain];
        }

        // Plain text: single <t> element
        $tNode = $xpath->query('x:t', $si)->item(0);
        $text  = $tNode?->textContent ?? '';
        return ['runs' => [new MergedRun('', $text)], 'plain' => $text];
    }

    /**
     * Walk all worksheets and collect the set of shared string indices
     * referenced by cells with t="s".
     *
     * @return int[] Unique shared string indices, sorted ascending.
     */
    private function collectUsedSharedStringIndices(ZipArchive $zip): array
    {
        $used = [];

        foreach ($this->findWorksheetFiles($zip) as $sheetFile) {
            $content = $zip->getFromName($sheetFile);
            if ($content === false) {
                continue;
            }

            $dom   = $this->loadXml($content);
            $xpath = $this->makeXPath($dom);

            foreach ($xpath->query('//x:c[@t="s"]/x:v') as $vNode) {
                $idx = (int) $vNode->textContent;
                $used[$idx] = true;
            }
        }

        $keys = array_keys($used);
        sort($keys);
        return $keys;
    }

    /**
     * Collect inline strings (t="inlineStr") from all worksheets.
     *
     * @return array<int, array{file: string, cell: string, text: string}>
     */
    private function collectInlineStrings(ZipArchive $zip): array
    {
        $result = [];

        foreach ($this->findWorksheetFiles($zip) as $sheetFile) {
            $content = $zip->getFromName($sheetFile);
            if ($content === false) {
                continue;
            }

            $dom   = $this->loadXml($content);
            $xpath = $this->makeXPath($dom);

            foreach ($xpath->query('//x:c[@t="inlineStr"]') as $cell) {
                /** @var DOMElement $cell */
                $cellRef = $cell->getAttribute('r');
                $tNode   = $xpath->query('x:is/x:t', $cell)->item(0);
                if ($tNode === null || $tNode->textContent === '') {
                    continue;
                }
                $result[] = [
                    'file' => $sheetFile,
                    'cell' => $cellRef,
                    'text' => $tNode->textContent,
                ];
            }
        }

        return $result;
    }

    // ──────────────────────────────────────────────── skeleton writing ──

    /**
     * Replace translatable <si> elements in sharedStrings.xml with placeholders.
     *
     * @param array<int, array{runs: MergedRun[], plain: string}> $sharedStrings
     * @param array<int, int> $seqByIndex  shared string index → seq number
     */
    private function writeSharedStringsSkeleton(
        ZipArchive $zip,
        array $sharedStrings,
        array $seqByIndex,
    ): void {
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if ($content === false) {
            return;
        }

        $dom   = $this->loadXml($content);
        $xpath = $this->makeXPath($dom);

        $siNodes = iterator_to_array($xpath->query('//x:sst/x:si'));

        foreach ($siNodes as $idx => $si) {
            if (!isset($seqByIndex[$idx])) {
                continue;
            }

            $seq         = $seqByIndex[$idx];
            $placeholder = sprintf('{{SEG:%03d}}', $seq);

            // Remove all children and replace with a single <t>placeholder</t>
            while ($si->firstChild) {
                $si->removeChild($si->firstChild);
            }

            $tEl = $dom->createElementNS(self::SS_NS, 't');
            $tEl->appendChild($dom->createTextNode($placeholder));
            $si->appendChild($tEl);
        }

        $zip->addFromString('xl/sharedStrings.xml', $dom->saveXML());
    }

    /**
     * Replace inline string cells in worksheets with placeholders.
     *
     * @param SegmentPair[] $pairs
     */
    private function writeInlineStringSkeleton(ZipArchive $zip, array $pairs): void
    {
        // Group inline pairs by worksheet file
        $byFile = [];
        foreach ($pairs as $pair) {
            if ($pair->context['type'] !== 'inline') {
                continue;
            }
            $byFile[$pair->context['file']][] = $pair;
        }

        foreach ($byFile as $sheetFile => $filePairs) {
            $content = $zip->getFromName($sheetFile);
            if ($content === false) {
                continue;
            }

            $dom   = $this->loadXml($content);
            $xpath = $this->makeXPath($dom);

            foreach ($filePairs as $pair) {
                $cellRef = $pair->context['cell'];
                $seq     = $pair->context['seq'];

                // Find the cell by its r attribute
                $cells = $xpath->query('//x:c[@r="' . $cellRef . '"]');
                if ($cells->length === 0) {
                    continue;
                }

                /** @var DOMElement $cell */
                $cell    = $cells->item(0);
                $tNode   = $xpath->query('x:is/x:t', $cell)->item(0);
                if ($tNode !== null) {
                    $tNode->nodeValue = sprintf('{{SEG:%03d}}', $seq);
                }
            }

            $zip->addFromString($sheetFile, $dom->saveXML());
        }
    }

    // ──────────────────────────────────────────────── rebuild helpers ──

    /**
     * Replace a skeleton <si><t>{{SEG:N}}</t></si> with the translated content.
     * For rich-text targets, reconstructs <r><rPr>…</rPr><t>…</t></r> elements.
     */
    private function replaceSimpleSiWithSegment(
        DOMElement $si,
        Segment $segment,
        string $baseRpr,
        DOMDocument $dom,
    ): void {
        // Remove all existing children
        while ($si->firstChild) {
            $si->removeChild($si->firstChild);
        }

        $mergedRuns = OoxmlRunMerger::segmentToRuns($segment, $baseRpr);

        if (count($mergedRuns) === 1 && $mergedRuns[0]->rPrXml === '') {
            // Plain text: simple <t> element
            $tEl = $dom->createElementNS(self::SS_NS, 't');
            $tEl->appendChild($dom->createTextNode($mergedRuns[0]->text));
            $si->appendChild($tEl);
            return;
        }

        // Rich text: one <r> per run
        foreach ($mergedRuns as $run) {
            $rEl = $dom->createElementNS(self::SS_NS, 'r');

            if ($run->rPrXml !== '') {
                $rEl->appendChild($this->importRprNode($run->rPrXml, $dom));
            }

            $tEl = $dom->createElementNS(self::SS_NS, 't');
            $tEl->appendChild($dom->createTextNode($run->text));
            $rEl->appendChild($tEl);
            $si->appendChild($rEl);
        }
    }

    /**
     * Parse a serialized <rPr> XML string and import it into $dom.
     * Falls back to an empty fragment if the XML is unparseable.
     */
    private function importRprNode(string $rPrXml, DOMDocument $dom): \DOMNode
    {
        $temp = new DOMDocument();
        libxml_use_internal_errors(true);
        $temp->loadXML($rPrXml);
        libxml_clear_errors();

        if ($temp->documentElement !== null) {
            return $dom->importNode($temp->documentElement, deep: true);
        }

        return $dom->createElementNS(self::SS_NS, 'rPr');
    }

    // ──────────────────────────────────────────────── utilities ──

    /**
     * Find all worksheet XML paths inside the ZIP.
     * Matches the standard XLSX convention: xl/worksheets/sheet*.xml.
     *
     * @return string[]
     */
    private function findWorksheetFiles(ZipArchive $zip): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && str_starts_with($name, 'xl/worksheets/sheet') && str_ends_with($name, '.xml')) {
                $files[] = $name;
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Returns true for shared strings that should not be translated:
     * pure numbers, currency values, percentages, and empty strings.
     */
    private function isNonTranslatable(string $text): bool
    {
        if (trim($text) === '') {
            return true;
        }
        return (bool) preg_match(self::NUMERIC_PATTERN, $text);
    }

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
        $xpath->registerNamespace('x', self::SS_NS);
        return $xpath;
    }
}
