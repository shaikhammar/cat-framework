<?php

declare(strict_types=1);

namespace CatFramework\FilterXlsx\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\Segment;
use CatFramework\FilterXlsx\XlsxFilter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class XlsxFilterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cat-xlsx-test-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);

        foreach (glob(sys_get_temp_dir() . '/cat-*.skl') ?: [] as $file) {
            @unlink($file);
        }
    }

    // ──────────────────────────────────────────────── supports() ──

    public function testSupportsXlsxExtension(): void
    {
        $filter = new XlsxFilter();
        $this->assertTrue($filter->supports('report.xlsx'));
        $this->assertTrue($filter->supports('REPORT.XLSX'));
    }

    public function testSupportsXlsxMimeType(): void
    {
        $this->assertTrue((new XlsxFilter())->supports(
            'upload',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ));
    }

    public function testDoesNotSupportOtherExtensions(): void
    {
        $filter = new XlsxFilter();
        $this->assertFalse($filter->supports('report.xls'));
        $this->assertFalse($filter->supports('report.csv'));
        $this->assertFalse($filter->supports('report.ods'));
    }

    public function testGetSupportedExtensions(): void
    {
        $this->assertSame(['.xlsx'], (new XlsxFilter())->getSupportedExtensions());
    }

    // ──────────────────────────────────────────────── extract basics ──

    public function testExtractPlainSharedStrings(): void
    {
        $path = $this->buildXlsx(
            sharedStrings: ['Product Name', 'Price', 'Laptop'],
            sheet: [['A1' => 0], ['B1' => 1], ['A2' => 2]],
        );

        $doc   = (new XlsxFilter())->extract($path, 'en-US', 'de-DE');
        $pairs = $doc->getSegmentPairs();

        $this->assertSame(3, $doc->count());
        $this->assertSame('Product Name', $pairs[0]->source->getPlainText());
        $this->assertSame('Price', $pairs[1]->source->getPlainText());
        $this->assertSame('Laptop', $pairs[2]->source->getPlainText());
    }

    public function testExtractAssignsSequentialSegmentIds(): void
    {
        $path  = $this->buildXlsx(
            sharedStrings: ['One', 'Two', 'Three'],
            sheet: [['A1' => 0], ['A2' => 1], ['A3' => 2]],
        );
        $pairs = (new XlsxFilter())->extract($path, 'en', 'de')->getSegmentPairs();

        $this->assertSame('seg-1', $pairs[0]->source->id);
        $this->assertSame('seg-2', $pairs[1]->source->id);
        $this->assertSame('seg-3', $pairs[2]->source->id);
    }

    public function testDuplicateSharedStringIndexProducesOneSegment(): void
    {
        // Index 2 ("Laptop") is used by both A2 and A3 — only one SegmentPair expected.
        $path = $this->buildXlsx(
            sharedStrings: ['Header', 'Price', 'Laptop'],
            sheet: [['A1' => 0], ['B1' => 1], ['A2' => 2], ['A3' => 2]],
        );

        $doc = (new XlsxFilter())->extract($path, 'en', 'de');
        $this->assertSame(3, $doc->count(), 'Three unique strings, not four cells');
    }

    public function testNumericStringsAreSkipped(): void
    {
        $path = $this->buildXlsx(
            sharedStrings: ['Product', '2024', '$1,500', '100%', 'Description'],
            sheet: [['A1' => 0], ['B1' => 1], ['C1' => 2], ['D1' => 3], ['E1' => 4]],
        );

        $doc   = (new XlsxFilter())->extract($path, 'en', 'de');
        $pairs = $doc->getSegmentPairs();

        $this->assertSame(2, $doc->count());
        $this->assertSame('Product', $pairs[0]->source->getPlainText());
        $this->assertSame('Description', $pairs[1]->source->getPlainText());
    }

    public function testUnusedSharedStringsAreNotExtracted(): void
    {
        // Shared strings file has 3 entries, but only index 0 is used by any cell.
        $path = $this->buildXlsx(
            sharedStrings: ['Used', 'Unused A', 'Unused B'],
            sheet: [['A1' => 0]],
        );

        $doc = (new XlsxFilter())->extract($path, 'en', 'de');
        $this->assertSame(1, $doc->count());
        $this->assertSame('Used', $doc->getSegmentPairs()[0]->source->getPlainText());
    }

    public function testEmptySharedStringIsSkipped(): void
    {
        $path = $this->buildXlsx(
            sharedStrings: ['Hello', ''],
            sheet: [['A1' => 0], ['B1' => 1]],
        );

        $doc = (new XlsxFilter())->extract($path, 'en', 'de');
        $this->assertSame(1, $doc->count());
    }

    // ──────────────────────────────────────────────── rich text ──

    public function testRichTextSharedStringProducesInlineCodes(): void
    {
        $path = $this->buildXlsxRichText(
            richSi: [
                ['rPr' => '<x:rPr><x:b/></x:rPr>', 'text' => 'Bold'],
                ['rPr' => '',                        'text' => ' normal'],
            ],
            cellIndex: 0,
        );

        $pairs    = (new XlsxFilter())->extract($path, 'en', 'de')->getSegmentPairs();
        $elements = $pairs[0]->source->getElements();

        $this->assertSame('Bold normal', $pairs[0]->source->getPlainText());
        // Expect: OPENING, 'Bold', CLOSING, ' normal'
        $this->assertCount(4, $elements);
        $this->assertSame(InlineCodeType::OPENING, $elements[0]->type);
        $this->assertSame('Bold', $elements[1]);
        $this->assertSame(InlineCodeType::CLOSING, $elements[2]->type);
        $this->assertSame(' normal', $elements[3]);
    }

    // ──────────────────────────────────────────────── inline strings ──

    public function testInlineStringsAreExtracted(): void
    {
        $path = $this->buildXlsxWithInlineStr(['Hello inline', 'Second inline']);

        $doc   = (new XlsxFilter())->extract($path, 'en', 'de');
        $pairs = $doc->getSegmentPairs();

        $this->assertSame(2, $doc->count());
        $this->assertSame('Hello inline', $pairs[0]->source->getPlainText());
        $this->assertSame('Second inline', $pairs[1]->source->getPlainText());
    }

    // ──────────────────────────────────────────────── rebuild ──

    public function testRebuildReplacesPlaceholdersWithTranslations(): void
    {
        $path = $this->buildXlsx(
            sharedStrings: ['Hello', 'World'],
            sheet: [['A1' => 0], ['B1' => 1]],
        );

        $filter = new XlsxFilter();
        $doc    = $filter->extract($path, 'en', 'de');

        $pairs = $doc->getSegmentPairs();
        $pairs[0]->target = new Segment($pairs[0]->source->id, ['Hallo']);
        $pairs[1]->target = new Segment($pairs[1]->source->id, ['Welt']);

        $outputPath = $this->tmpDir . '/output.xlsx';
        $filter->rebuild($doc, $outputPath);

        $this->assertFileExists($outputPath);

        // Inspect rebuilt sharedStrings.xml
        $zip = new ZipArchive();
        $zip->open($outputPath);
        $ssContent = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        $this->assertStringContainsString('Hallo', $ssContent);
        $this->assertStringContainsString('Welt', $ssContent);
        $this->assertStringNotContainsString('{{SEG:', $ssContent);
    }

    public function testRebuildFallsBackToSourceWhenNoTarget(): void
    {
        $path = $this->buildXlsx(
            sharedStrings: ['Original text'],
            sheet: [['A1' => 0]],
        );

        $filter = new XlsxFilter();
        $doc    = $filter->extract($path, 'en', 'de');
        // No target set — rebuild should use source text

        $outputPath = $this->tmpDir . '/output.xlsx';
        $filter->rebuild($doc, $outputPath);

        $zip = new ZipArchive();
        $zip->open($outputPath);
        $ssContent = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        $this->assertStringContainsString('Original text', $ssContent);
    }

    public function testRebuildDeletesCalcChain(): void
    {
        $path = $this->buildXlsxWithCalcChain(
            sharedStrings: ['Text'],
            sheet: [['A1' => 0]],
        );

        $filter = new XlsxFilter();
        $doc    = $filter->extract($path, 'en', 'de');

        $outputPath = $this->tmpDir . '/output.xlsx';
        $filter->rebuild($doc, $outputPath);

        $zip = new ZipArchive();
        $zip->open($outputPath);
        $hasCalcChain = $zip->locateName('xl/calcChain.xml') !== false;
        $zip->close();

        $this->assertFalse($hasCalcChain, 'calcChain.xml must be deleted from rebuilt XLSX');
    }

    public function testRebuildMissingSkeletonThrows(): void
    {
        $doc = new \CatFramework\Core\Model\BilingualDocument(
            sourceLanguage: 'en',
            targetLanguage: 'de',
            originalFile: 'test.xlsx',
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            skeleton: ['path' => '/nonexistent/path.skl'],
        );

        $this->expectException(\CatFramework\Core\Exception\FilterException::class);
        (new XlsxFilter())->rebuild($doc, $this->tmpDir . '/out.xlsx');
    }

    // ──────────────────────────────────────────────── XLSX builders ──

    /**
     * Build a minimal XLSX with shared strings and one worksheet.
     *
     * @param string[]                $sharedStrings  Ordered list of string values.
     * @param array<array<string,int>> $sheet          Rows: [['CellRef' => ssIndex], ...].
     */
    private function buildXlsx(array $sharedStrings, array $sheet): string
    {
        $path = $this->tmpDir . '/' . uniqid() . '.xlsx';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml($sharedStrings));
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheetXml($sheet));
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());

        $zip->close();
        return $path;
    }

    /** Build a minimal XLSX that also contains xl/calcChain.xml. */
    private function buildXlsxWithCalcChain(array $sharedStrings, array $sheet): string
    {
        $path = $this->buildXlsx($sharedStrings, $sheet);

        $zip = new ZipArchive();
        $zip->open($path);
        $zip->addFromString('xl/calcChain.xml', '<?xml version="1.0" encoding="UTF-8"?><calcChain xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>');
        $zip->close();

        return $path;
    }

    /**
     * Build a minimal XLSX with a single rich-text shared string.
     *
     * @param array<array{rPr: string, text: string}> $richSi  Run definitions.
     */
    private function buildXlsxRichText(array $richSi, int $cellIndex): string
    {
        $path = $this->tmpDir . '/' . uniqid() . '.xlsx';

        $runs = '';
        foreach ($richSi as $run) {
            $rPr  = $run['rPr'] !== '' ? $run['rPr'] : '';
            $runs .= '<x:r>' . $rPr . '<x:t>' . htmlspecialchars($run['text'], ENT_XML1) . '</x:t></x:r>';
        }

        $ssXml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<x:sst xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1">'
            . '<x:si>' . $runs . '</x:si>'
            . '</x:sst>';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('xl/sharedStrings.xml', $ssXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheetXml([['A1' => $cellIndex]]));
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->close();

        return $path;
    }

    /** Build a minimal XLSX with inline string cells (t="inlineStr"). */
    private function buildXlsxWithInlineStr(array $texts): string
    {
        $path = $this->tmpDir . '/' . uniqid() . '.xlsx';

        $cells = '';
        $cols  = range('A', 'Z');
        foreach ($texts as $i => $text) {
            $ref    = $cols[$i] . '1';
            $cells .= '<x:c r="' . $ref . '" t="inlineStr"><x:is><x:t>' . htmlspecialchars($text, ENT_XML1) . '</x:t></x:is></x:c>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<x:worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<x:sheetData><x:row r="1">' . $cells . '</x:row></x:sheetData>'
            . '</x:worksheet>';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->close();

        return $path;
    }

    // ──────────────────────────────────────── XML fragment helpers ──

    private function sharedStringsXml(array $strings): string
    {
        $count = count($strings);
        $items = '';
        foreach ($strings as $str) {
            $items .= '<x:si><x:t>' . htmlspecialchars($str, ENT_XML1) . '</x:t></x:si>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<x:sst xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' count="' . $count . '" uniqueCount="' . $count . '">'
            . $items . '</x:sst>';
    }

    private function worksheetXml(array $rows): string
    {
        $rowsByRow = [];
        foreach ($rows as $row) {
            foreach ($row as $cellRef => $ssIndex) {
                preg_match('/^([A-Z]+)(\d+)$/', $cellRef, $m);
                $rowNum = $m[2] ?? '1';
                $rowsByRow[$rowNum][] = '<x:c r="' . $cellRef . '" t="s"><x:v>' . $ssIndex . '</x:v></x:c>';
            }
        }

        $rowXml = '';
        foreach ($rowsByRow as $rowNum => $cells) {
            $rowXml .= '<x:row r="' . $rowNum . '">' . implode('', $cells) . '</x:row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<x:worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<x:sheetData>' . $rowXml . '</x:sheetData>'
            . '</x:worksheet>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<x:workbook xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<x:sheets><x:sheet name="Sheet1" sheetId="1" r:id="rId1"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>'
            . '</x:sheets></x:workbook>';
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '</Types>';
    }

    // ──────────────────────────────────────────── test cleanup ──

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $full = $dir . '/' . $file;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($dir);
    }
}
