<?php

declare(strict_types=1);

namespace CatFramework\FilterDocx\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\FilterDocx\DocxFilter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class DocxFilterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cat-docx-test-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);

        // Clean up skeleton files created during tests.
        foreach (glob(sys_get_temp_dir() . '/cat-*.skl') ?: [] as $file) {
            @unlink($file);
        }
    }

    // ──────────────────────────────────────────────── supports() tests ──

    public function testSupportsDocxExtension(): void
    {
        $filter = new DocxFilter();
        $this->assertTrue($filter->supports('document.docx'));
        $this->assertTrue($filter->supports('MY_FILE.DOCX'));
    }

    public function testSupportsOoxmlMimeType(): void
    {
        $filter = new DocxFilter();
        $this->assertTrue($filter->supports(
            'upload',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ));
    }

    public function testDoesNotSupportOtherExtensions(): void
    {
        $filter = new DocxFilter();
        $this->assertFalse($filter->supports('document.txt'));
        $this->assertFalse($filter->supports('document.doc'));
        $this->assertFalse($filter->supports('document.odt'));
    }

    // ────────────────────────────────────────────── extract() basics ──

    public function testExtractPlainParagraphs(): void
    {
        $path = $this->buildDocx(['Hello world', 'Second paragraph']);

        $doc   = (new DocxFilter())->extract($path, 'en-US', 'fr-FR');
        $pairs = $doc->getSegmentPairs();

        $this->assertSame(2, $doc->count());
        $this->assertSame('Hello world', $pairs[0]->source->getPlainText());
        $this->assertSame('Second paragraph', $pairs[1]->source->getPlainText());
    }

    public function testExtractAssignsSequentialSegmentIds(): void
    {
        $path  = $this->buildDocx(['First', 'Second', 'Third']);
        $pairs = (new DocxFilter())->extract($path, 'en-US', 'fr-FR')->getSegmentPairs();

        $this->assertSame('seg-1', $pairs[0]->source->id);
        $this->assertSame('seg-2', $pairs[1]->source->id);
        $this->assertSame('seg-3', $pairs[2]->source->id);
    }

    public function testEmptyParagraphsAreSkipped(): void
    {
        $path = $this->buildDocxRaw(<<<'XML'
            <w:p><w:r><w:t>First</w:t></w:r></w:p>
            <w:p></w:p>
            <w:p><w:r><w:t xml:space="preserve">   </w:t></w:r></w:p>
            <w:p><w:r><w:t>Last</w:t></w:r></w:p>
            XML);

        $doc = (new DocxFilter())->extract($path, 'en-US', 'fr-FR');

        $this->assertSame(2, $doc->count());
        $pairs = $doc->getSegmentPairs();
        $this->assertSame('First', $pairs[0]->source->getPlainText());
        $this->assertSame('Last', $pairs[1]->source->getPlainText());
    }

    // ─────────────────────────────────────────── run merging / InlineCodes ──

    public function testFragmentedRunsMergeIntoPlainText(): void
    {
        // Three runs with no rPr (same default formatting) — Word fragmentation.
        $path = $this->buildDocxRaw(<<<'XML'
            <w:p>
              <w:r><w:t>Hel</w:t></w:r>
              <w:r><w:t>lo </w:t></w:r>
              <w:r><w:t>world</w:t></w:r>
            </w:p>
            XML);

        $pair = (new DocxFilter())->extract($path, 'en-US', 'fr-FR')->getSegmentPairs()[0];

        $elements = $pair->source->getElements();
        $this->assertCount(1, $elements, 'Three same-format runs must merge into one plain string');
        $this->assertSame('Hello world', $elements[0]);
    }

    public function testFormattedRunsProduceInlineCodes(): void
    {
        $path = $this->buildDocxRaw(<<<'XML'
            <w:p>
              <w:r><w:t xml:space="preserve">Click the </w:t></w:r>
              <w:r><w:rPr><w:b/></w:rPr><w:t>Save</w:t></w:r>
              <w:r><w:t xml:space="preserve"> button.</w:t></w:r>
            </w:p>
            XML);

        $pair     = (new DocxFilter())->extract($path, 'en-US', 'fr-FR')->getSegmentPairs()[0];
        $elements = $pair->source->getElements();

        $this->assertSame('Click the Save button.', $pair->source->getPlainText());
        // Expected: 'Click the ', OPEN, 'Save', CLOSE, ' button.'
        $this->assertCount(5, $elements);
        $this->assertSame('Click the ', $elements[0]);
        $this->assertInstanceOf(InlineCode::class, $elements[1]);
        $this->assertSame(InlineCodeType::OPENING, $elements[1]->type);
        $this->assertSame('Save', $elements[2]);
        $this->assertInstanceOf(InlineCode::class, $elements[3]);
        $this->assertSame(InlineCodeType::CLOSING, $elements[3]->type);
        $this->assertSame(' button.', $elements[4]);
    }

    public function testInlineCodePairsHaveMatchingIds(): void
    {
        $path = $this->buildDocxRaw(<<<'XML'
            <w:p>
              <w:r><w:t xml:space="preserve">a </w:t></w:r>
              <w:r><w:rPr><w:b/></w:rPr><w:t>bold</w:t></w:r>
              <w:r><w:t xml:space="preserve"> and </w:t></w:r>
              <w:r><w:rPr><w:i/></w:rPr><w:t>italic</w:t></w:r>
              <w:r><w:t>.</w:t></w:r>
            </w:p>
            XML);

        $codes = (new DocxFilter())->extract($path, 'en-US', 'fr-FR')
            ->getSegmentPairs()[0]->source->getInlineCodes();

        // 2 pairs = 4 codes: open/close bold, open/close italic
        $this->assertCount(4, $codes);
        $this->assertSame($codes[0]->id, $codes[1]->id); // bold pair
        $this->assertSame($codes[2]->id, $codes[3]->id); // italic pair
        $this->assertNotSame($codes[0]->id, $codes[2]->id);
    }

    // ─────────────────────────────────────────────── round-trip tests ──

    /**
     * Round-trip test template: extract → set targets → rebuild → re-extract.
     * All round-trip tests follow this pattern to verify rebuild correctness.
     */
    public function testRoundTripPlainText(): void
    {
        $path   = $this->buildDocx(['Hello world', 'Second paragraph']);
        $filter = new DocxFilter();
        $doc    = $filter->extract($path, 'en-US', 'fr-FR');

        $pairs = $doc->getSegmentPairs();
        $pairs[0]->target = new Segment('seg-1', ['Bonjour le monde']);
        $pairs[1]->target = new Segment('seg-2', ['Deuxième paragraphe']);

        $out = $this->tmpDir . '/output.docx';
        $filter->rebuild($doc, $out);

        $rebuilt = $filter->extract($out, 'fr-FR', 'en-US')->getSegmentPairs();
        $this->assertSame('Bonjour le monde', $rebuilt[0]->source->getPlainText());
        $this->assertSame('Deuxième paragraphe', $rebuilt[1]->source->getPlainText());
    }

    /**
     * Untranslated segments fall back to source text in the rebuilt file.
     */
    public function testRoundTripFallsBackToSourceWhenUntranslated(): void
    {
        $path   = $this->buildDocx(['Only segment']);
        $filter = new DocxFilter();
        $doc    = $filter->extract($path, 'en-US', 'fr-FR');
        // No target set — should fall back to source.

        $out = $this->tmpDir . '/fallback.docx';
        $filter->rebuild($doc, $out);

        $rebuilt = $filter->extract($out, 'en-US', 'fr-FR')->getSegmentPairs();
        $this->assertSame('Only segment', $rebuilt[0]->source->getPlainText());
    }

    /**
     * InlineCode data carries the rPr XML; rebuild must reconstruct runs
     * with the correct formatting so a second extract sees the same codes.
     */
    public function testRoundTripPreservesInlineCodes(): void
    {
        $path   = $this->buildDocxRaw(<<<'XML'
            <w:p>
              <w:r><w:t xml:space="preserve">Click the </w:t></w:r>
              <w:r><w:rPr><w:b/></w:rPr><w:t>Save</w:t></w:r>
              <w:r><w:t xml:space="preserve"> button.</w:t></w:r>
            </w:p>
            XML);
        $filter = new DocxFilter();
        $doc    = $filter->extract($path, 'en-US', 'fr-FR');

        $pair  = $doc->getSegmentPairs()[0];
        $codes = $pair->source->getInlineCodes(); // [OPEN(bold), CLOSE(bold)]

        $pair->target = new Segment('seg-1', [
            'Cliquez sur ',
            $codes[0],      // opening bold
            'Enregistrer',
            $codes[1],      // closing bold
            '.',
        ]);

        $out = $this->tmpDir . '/fmt-output.docx';
        $filter->rebuild($doc, $out);

        $rebuilt     = $filter->extract($out, 'fr-FR', 'en-US')->getSegmentPairs()[0];
        $rebuiltText = $rebuilt->source->getPlainText();
        $this->assertSame('Cliquez sur Enregistrer.', $rebuiltText);
        $this->assertCount(2, $rebuilt->source->getInlineCodes(), 'Bold InlineCodes must survive the round-trip');
    }

    // ─────────────────────────────────── fixture builder helpers ──

    private function buildDocx(array $plainParagraphs): string
    {
        $xml = '';
        foreach ($plainParagraphs as $text) {
            $escaped = htmlspecialchars($text, ENT_XML1);
            $xml    .= "<w:p><w:r><w:t>{$escaped}</w:t></w:r></w:p>\n";
        }
        return $this->buildDocxRaw($xml);
    }

    private function buildDocxRaw(string $paragraphXml): string
    {
        $path = $this->tmpDir . '/' . uniqid('fixture') . '.docx';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">',
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
            '<Default Extension="xml" ContentType="application/xml"/>',
            '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>',
            '</Types>',
        ]));

        $zip->addFromString('_rels/.rels', implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">',
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>',
            '</Relationships>',
        ]));

        $zip->addFromString('word/_rels/document.xml.rels', implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
        ]));

        $zip->addFromString('word/document.xml', implode('', [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">',
            '<w:body>',
            $paragraphXml,
            '<w:sectPr/>',
            '</w:body>',
            '</w:document>',
        ]));

        $zip->close();

        return $path;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
