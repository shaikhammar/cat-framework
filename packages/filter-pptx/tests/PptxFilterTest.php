<?php

declare(strict_types=1);

namespace CatFramework\FilterPptx\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\Segment;
use CatFramework\FilterPptx\PptxFilter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class PptxFilterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cat-pptx-test-' . uniqid();
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

    public function testSupportsExtension(): void
    {
        $filter = new PptxFilter();
        $this->assertTrue($filter->supports('deck.pptx'));
        $this->assertTrue($filter->supports('DECK.PPTX'));
        $this->assertFalse($filter->supports('deck.ppt'));
        $this->assertFalse($filter->supports('deck.odp'));
    }

    public function testSupportsMimeType(): void
    {
        $this->assertTrue((new PptxFilter())->supports(
            'upload',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ));
    }

    public function testGetSupportedExtensions(): void
    {
        $this->assertSame(['.pptx'], (new PptxFilter())->getSupportedExtensions());
    }

    // ──────────────────────────────────────────── extract basics ──

    public function testExtractSingleSlide(): void
    {
        $path = $this->buildPptx(slides: [['Hello world']]);

        $doc   = (new PptxFilter())->extract($path, 'en-US', 'de-DE');
        $pairs = $doc->getSegmentPairs();

        $this->assertSame(1, $doc->count());
        $this->assertSame('Hello world', $pairs[0]->source->getPlainText());
        $this->assertSame('slide', $pairs[0]->context['source']);
    }

    public function testExtractMultipleSlides(): void
    {
        $path = $this->buildPptx(slides: [
            ['First slide'],
            ['Second slide'],
        ]);

        $doc   = (new PptxFilter())->extract($path, 'en-US', 'de-DE');
        $pairs = $doc->getSegmentPairs();

        $this->assertSame(2, $doc->count());
        $this->assertSame('First slide', $pairs[0]->source->getPlainText());
        $this->assertSame('Second slide', $pairs[1]->source->getPlainText());
    }

    public function testExtractMultipleParagraphsFromOneSlide(): void
    {
        $path = $this->buildPptx(slides: [['Title text', 'Body text']]);

        $doc = (new PptxFilter())->extract($path, 'en-US', 'de-DE');

        $this->assertSame(2, $doc->count());
        $texts = array_map(fn($p) => $p->source->getPlainText(), $doc->getSegmentPairs());
        $this->assertSame(['Title text', 'Body text'], $texts);
    }

    public function testExtractNotesSlide(): void
    {
        $path = $this->buildPptx(
            slides: [['Slide content']],
            notes: ['Speaker note here'],
        );

        $doc   = (new PptxFilter())->extract($path, 'en-US', 'de-DE');
        $pairs = $doc->getSegmentPairs();

        $this->assertSame(2, $doc->count());

        $slideSegments = array_filter($pairs, fn($p) => $p->context['source'] === 'slide');
        $notesSegments = array_filter($pairs, fn($p) => $p->context['source'] === 'notes');

        $this->assertCount(1, $slideSegments);
        $this->assertCount(1, $notesSegments);
        $this->assertSame('Speaker note here', array_values($notesSegments)[0]->source->getPlainText());
    }

    public function testSkipsHiddenSlides(): void
    {
        $path = $this->tmpDir . '/hidden-' . uniqid() . '.pptx';

        $A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

        // slide1 is visible, slide2 is hidden (show="0")
        $visibleXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:p="{$P}" xmlns:a="{$A}">
  <p:cSld><p:spTree><p:sp><p:txBody>
    <a:p><a:r><a:t>Visible slide</a:t></a:r></a:p>
  </p:txBody></p:sp></p:spTree></p:cSld>
</p:sld>
XML;
        $hiddenXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:p="{$P}" xmlns:a="{$A}" show="0">
  <p:cSld><p:spTree><p:sp><p:txBody>
    <a:p><a:r><a:t>Hidden slide</a:t></a:r></a:p>
  </p:txBody></p:sp></p:spTree></p:cSld>
</p:sld>
XML;
        $RELS = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $emptyRels = "<Relationships xmlns=\"{$RELS}\"/>";

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', $this->buildContentTypes(2, false));
        $zip->addFromString('_rels/.rels', $this->buildRootRels());
        $zip->addFromString('ppt/presentation.xml', $this->buildPresentation(2));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->buildPresentationRels(2));
        $zip->addFromString('ppt/slides/slide1.xml', $visibleXml);
        $zip->addFromString('ppt/slides/_rels/slide1.xml.rels', $emptyRels);
        $zip->addFromString('ppt/slides/slide2.xml', $hiddenXml);
        $zip->addFromString('ppt/slides/_rels/slide2.xml.rels', $emptyRels);
        $zip->close();

        $doc = (new PptxFilter())->extract($path, 'en-US', 'de-DE');

        $this->assertSame(1, $doc->count());
        $this->assertSame('Visible slide', $doc->getSegmentPairs()[0]->source->getPlainText());
    }

    public function testSkipsEmptyParagraphs(): void
    {
        $path = $this->buildPptx(slides: [['Real text', '   ', '']]);

        $doc = (new PptxFilter())->extract($path, 'en-US', 'de-DE');

        $this->assertSame(1, $doc->count());
        $this->assertSame('Real text', $doc->getSegmentPairs()[0]->source->getPlainText());
    }

    // ─────────────────────────────────────────── rich text / inline codes ──

    public function testExtractRichTextCreatesInlineCodes(): void
    {
        $boldRpr = '<a:rPr xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" b="1"/>';
        $path    = $this->buildPptxWithRichText(runs: [
            ['text' => 'Normal ', 'rPrXml' => ''],
            ['text' => 'bold',    'rPrXml' => $boldRpr],
            ['text' => ' text',   'rPrXml' => ''],
        ]);

        $doc      = (new PptxFilter())->extract($path, 'en-US', 'de-DE');
        $elements = $doc->getSegmentPairs()[0]->source->getElements();

        // Expect: "Normal " + OPENING code + "bold" + CLOSING code + " text"
        $types = array_map(
            fn($e) => is_string($e) ? 'str' : $e->type->value,
            $elements,
        );

        $this->assertContains(InlineCodeType::OPENING->value, $types);
        $this->assertContains(InlineCodeType::CLOSING->value, $types);
    }

    // ──────────────────────────────────────────────── rebuild ──

    public function testRebuildReplacesText(): void
    {
        $path   = $this->buildPptx(slides: [['Hello world']]);
        $filter = new PptxFilter();

        $doc  = $filter->extract($path, 'en-US', 'de-DE');
        $pair = $doc->getSegmentPairs()[0];
        $pair->target = new Segment($pair->source->id, ['Hallo Welt']);

        $outPath = $this->tmpDir . '/out.pptx';
        $filter->rebuild($doc, $outPath);

        $this->assertFileExists($outPath);

        $zip     = new ZipArchive();
        $zip->open($outPath);
        $content = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        $this->assertStringContainsString('Hallo Welt', $content);
        $this->assertStringNotContainsString('Hello world', $content);
        $this->assertStringNotContainsString('{{SEG:', $content);
    }

    public function testRebuildRtl(): void
    {
        $path   = $this->buildPptx(slides: [['Hello world']]);
        $filter = new PptxFilter();

        $doc  = $filter->extract($path, 'en-US', 'ar-SA');
        $pair = $doc->getSegmentPairs()[0];
        $pair->target = new Segment($pair->source->id, ['مرحبا']);

        $outPath = $this->tmpDir . '/out-rtl.pptx';
        $filter->rebuild($doc, $outPath);

        $zip     = new ZipArchive();
        $zip->open($outPath);
        $content = $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        // RTL attribute must appear on the run properties and/or paragraph properties
        $this->assertMatchesRegularExpression('/rtl=["\']1["\']/', $content);
    }

    // ─────────────────────────────────────────────── PPTX builders ──

    /**
     * Builds a minimal .pptx with one or more slides.
     *
     * @param string[][] $slides  Each element is an array of paragraph texts for that slide.
     * @param string[]   $notes   One notes text per slide (applied to slide 1 only for simplicity).
     */
    private function buildPptx(array $slides, array $notes = []): string
    {
        $path = $this->tmpDir . '/test-' . uniqid() . '.pptx';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);

        $slideCount = count($slides);

        $zip->addFromString('[Content_Types].xml', $this->buildContentTypes($slideCount, count($notes) > 0));
        $zip->addFromString('_rels/.rels', $this->buildRootRels());
        $zip->addFromString('ppt/presentation.xml', $this->buildPresentation($slideCount));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->buildPresentationRels($slideCount));

        foreach ($slides as $i => $paragraphs) {
            $n         = $i + 1;
            $noteText  = $notes[$i] ?? null;
            $zip->addFromString("ppt/slides/slide{$n}.xml", $this->buildSlide($paragraphs));
            $zip->addFromString("ppt/slides/_rels/slide{$n}.xml.rels", $this->buildSlideRels($n, $noteText !== null));

            if ($noteText !== null) {
                $zip->addFromString("ppt/notesSlides/notesSlide{$n}.xml", $this->buildNotesSlide($noteText));
            }
        }

        $zip->close();

        return $path;
    }

    /** Builds a slide with rich-text runs (varying rPr). */
    private function buildPptxWithRichText(array $runs): string
    {
        $path = $this->tmpDir . '/rich-' . uniqid() . '.pptx';

        $runsXml = '';
        foreach ($runs as $run) {
            $rPr = $run['rPrXml'] !== '' ? $run['rPrXml'] : '';
            $text = htmlspecialchars($run['text'], ENT_XML1);
            $runsXml .= "<a:r xmlns:a=\"http://schemas.openxmlformats.org/drawingml/2006/main\">{$rPr}<a:t>{$text}</a:t></a:r>";
        }

        $A   = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $P   = 'http://schemas.openxmlformats.org/presentationml/2006/main';
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:p="{$P}" xmlns:a="{$A}">
  <p:cSld><p:spTree>
    <p:sp><p:txBody>
      <a:p>{$runsXml}</a:p>
    </p:txBody></p:sp>
  </p:spTree></p:cSld>
</p:sld>
XML;

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', $this->buildContentTypes(1, false));
        $zip->addFromString('_rels/.rels', $this->buildRootRels());
        $zip->addFromString('ppt/presentation.xml', $this->buildPresentation(1));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->buildPresentationRels(1));
        $zip->addFromString('ppt/slides/slide1.xml', $xml);
        $zip->addFromString('ppt/slides/_rels/slide1.xml.rels', $this->buildSlideRels(1, false));
        $zip->close();

        return $path;
    }

    private function buildSlideRels(int $slideNumber, bool $hasNotes): string
    {
        $RELS = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $NOTES_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide';

        $rels = $hasNotes
            ? "<Relationship Id=\"rId1\" Type=\"{$NOTES_TYPE}\" Target=\"../notesSlides/notesSlide{$slideNumber}.xml\"/>"
            : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="{$RELS}">{$rels}</Relationships>
XML;
    }

    private function buildSlide(array $paragraphs): string
    {
        $A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

        $parasXml = '';
        foreach ($paragraphs as $text) {
            $escaped  = htmlspecialchars($text, ENT_XML1);
            $parasXml .= "<a:p><a:r><a:t>{$escaped}</a:t></a:r></a:p>";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:sld xmlns:p="{$P}" xmlns:a="{$A}">
  <p:cSld><p:spTree>
    <p:sp><p:txBody>{$parasXml}</p:txBody></p:sp>
  </p:spTree></p:cSld>
</p:sld>
XML;
    }

    private function buildNotesSlide(string $text): string
    {
        $A       = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $P       = 'http://schemas.openxmlformats.org/presentationml/2006/main';
        $escaped = htmlspecialchars($text, ENT_XML1);

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:notes xmlns:p="{$P}" xmlns:a="{$A}">
  <p:cSld><p:spTree>
    <p:sp><p:txBody>
      <a:p><a:r><a:t>{$escaped}</a:t></a:r></a:p>
    </p:txBody></p:sp>
  </p:spTree></p:cSld>
</p:notes>
XML;
    }

    private function buildContentTypes(int $slideCount, bool $hasNotes): string
    {
        $CT  = 'http://schemas.openxmlformats.org/package/2006/content-types';
        $PML = 'application/vnd.openxmlformats-officedocument.presentationml';

        $overrides = "<Override PartName=\"/ppt/presentation.xml\" ContentType=\"{$PML}.presentation.main+xml\"/>";
        for ($i = 1; $i <= $slideCount; $i++) {
            $overrides .= "<Override PartName=\"/ppt/slides/slide{$i}.xml\" ContentType=\"{$PML}.slide+xml\"/>";
        }
        if ($hasNotes) {
            $overrides .= "<Override PartName=\"/ppt/notesSlides/notesSlide1.xml\" ContentType=\"{$PML}.notesSlide+xml\"/>";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="{$CT}">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  {$overrides}
</Types>
XML;
    }

    private function buildRootRels(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>
</Relationships>
XML;
    }

    private function buildPresentation(int $slideCount): string
    {
        $P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

        $sldIds = '';
        for ($i = 1; $i <= $slideCount; $i++) {
            $id      = 256 + $i;
            $sldIds .= "<p:sldId id=\"{$id}\" r:id=\"rId{$i}\"/>";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<p:presentation xmlns:p="{$P}" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <p:sldIdLst>{$sldIds}</p:sldIdLst>
</p:presentation>
XML;
    }

    private function buildPresentationRels(int $slideCount): string
    {
        $rels = '';
        $type = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide';
        for ($i = 1; $i <= $slideCount; $i++) {
            $rels .= "<Relationship Id=\"rId{$i}\" Type=\"{$type}\" Target=\"slides/slide{$i}.xml\"/>";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  {$rels}
</Relationships>
XML;
    }

    // ─────────────────────────────────────────────── test utilities ──

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
