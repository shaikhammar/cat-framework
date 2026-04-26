<?php

declare(strict_types=1);

namespace CatFramework\Xliff\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Enum\SegmentStatus;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Xliff\XliffReader;
use CatFramework\Xliff\XliffWriter;
use PHPUnit\Framework\TestCase;

class XliffRoundTripTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/xliff_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    // --- metadata round-trips ---

    public function test_document_metadata_round_trips(): void
    {
        $doc = new BilingualDocument('en-US', 'fr-FR', 'manual.txt', 'text/plain');
        $doc->addSegmentPair(new SegmentPair(new Segment('s1', ['Hello.'])));

        $result = $this->writeAndRead($doc, 'meta.xlf');

        $this->assertSame('en-US',        $result->sourceLanguage);
        $this->assertSame('fr-FR',        $result->targetLanguage);
        $this->assertSame('manual.txt',   $result->originalFile);
        $this->assertSame('text/plain',   $result->mimeType);
    }

    public function test_html_datatype_round_trips(): void
    {
        $doc    = new BilingualDocument('en-US', 'de-DE', 'page.html', 'text/html');
        $doc->addSegmentPair(new SegmentPair(new Segment('s1', ['Heading'])));
        $result = $this->writeAndRead($doc, 'html.xlf');

        $this->assertSame('text/html', $result->mimeType);
    }

    // --- plain text segments ---

    public function test_plain_text_source_and_target_round_trip(): void
    {
        $doc = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain');
        $doc->addSegmentPair(new SegmentPair(
            source: new Segment('s1', ['Hello world.']),
            target: new Segment('s1', ['Bonjour le monde.']),
            status: SegmentStatus::Translated,
        ));

        $result = $this->writeAndRead($doc, 'plain.xlf');
        $pairs  = $result->getSegmentPairs();

        $this->assertCount(1, $pairs);
        $this->assertSame('Hello world.',        $pairs[0]->source->getPlainText());
        $this->assertSame('Bonjour le monde.',   $pairs[0]->target->getPlainText());
        $this->assertSame(SegmentStatus::Translated, $pairs[0]->status);
    }

    public function test_untranslated_segment_target_is_null(): void
    {
        $doc = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain');
        $doc->addSegmentPair(new SegmentPair(new Segment('s1', ['Hello.'])));

        $result = $this->writeAndRead($doc, 'untranslated.xlf');
        $pairs  = $result->getSegmentPairs();

        $this->assertNull($pairs[0]->target);
        $this->assertSame(SegmentStatus::Untranslated, $pairs[0]->status);
    }

    // --- segment statuses ---

    public function test_xliff_roundtrippable_statuses(): void
    {
        // XLIFF 1.2 has a fixed state vocabulary. Only these four statuses have
        // distinct round-trip representations. Draft and Rejected both map to
        // 'new'/'needs-translation' which read back as Untranslated — that is
        // expected behaviour (XLIFF is a file exchange format, not a DB).
        $cases = [
            [SegmentStatus::Untranslated, SegmentStatus::Untranslated],
            [SegmentStatus::Translated,   SegmentStatus::Translated],
            [SegmentStatus::Reviewed,     SegmentStatus::Reviewed],
            [SegmentStatus::Approved,     SegmentStatus::Approved],
        ];

        $doc = new BilingualDocument('en-US', 'fr-FR', 'states.txt', 'text/plain');
        foreach ($cases as $i => [$input, $_]) {
            $doc->addSegmentPair(new SegmentPair(
                source: new Segment('s' . $i, ['Text.']),
                target: new Segment('s' . $i, ['Texte.']),
                status: $input,
            ));
        }

        $result = $this->writeAndRead($doc, 'states.xlf');
        $pairs  = $result->getSegmentPairs();

        foreach ($cases as $i => [, $expected]) {
            $this->assertSame($expected, $pairs[$i]->status, "Status mismatch at index {$i}");
        }
    }

    // --- locked segments ---

    public function test_locked_segment_round_trips(): void
    {
        $doc = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain');
        $doc->addSegmentPair(new SegmentPair(new Segment('s1', ['Locked.']), isLocked: true));
        $doc->addSegmentPair(new SegmentPair(new Segment('s2', ['Unlocked.']), isLocked: false));

        $result = $this->writeAndRead($doc, 'lock.xlf');
        $pairs  = $result->getSegmentPairs();

        $this->assertTrue($pairs[0]->isLocked);
        $this->assertFalse($pairs[1]->isLocked);
    }

    // --- inline codes ---

    public function test_opening_and_closing_codes_round_trip(): void
    {
        $elements = [
            'Hello ',
            new InlineCode('b1', InlineCodeType::OPENING,  '<b>',  '<b>'),
            'world',
            new InlineCode('b1', InlineCodeType::CLOSING,  '</b>', '</b>'),
            '.',
        ];
        $result = $this->roundTripSegment($elements, 'bpt.xlf');
        $codes  = $result->getInlineCodes();

        $this->assertCount(2, $codes);

        $this->assertSame('b1',                  $codes[0]->id);
        $this->assertSame(InlineCodeType::OPENING, $codes[0]->type);
        $this->assertSame('<b>',                 $codes[0]->data);
        $this->assertSame('<b>',                 $codes[0]->displayText);
        $this->assertFalse($codes[0]->isIsolated);

        $this->assertSame('b1',                  $codes[1]->id);
        $this->assertSame(InlineCodeType::CLOSING, $codes[1]->type);
        $this->assertSame('</b>',                $codes[1]->data);
        $this->assertFalse($codes[1]->isIsolated);
    }

    public function test_standalone_code_round_trips(): void
    {
        $elements = [
            'Line one.',
            new InlineCode('br1', InlineCodeType::STANDALONE, '<br/>', '<br/>'),
            'Line two.',
        ];
        $result = $this->roundTripSegment($elements, 'ph.xlf');
        $codes  = $result->getInlineCodes();

        $this->assertCount(1, $codes);
        $this->assertSame('br1',                     $codes[0]->id);
        $this->assertSame(InlineCodeType::STANDALONE, $codes[0]->type);
        $this->assertSame('<br/>',                    $codes[0]->data);
        $this->assertFalse($codes[0]->isIsolated);
    }

    public function test_isolated_codes_round_trip(): void
    {
        $elements = [
            new InlineCode('b1', InlineCodeType::OPENING, '<b>',  '<b>',  true),
            'Bold text.',
            new InlineCode('b1', InlineCodeType::CLOSING, '</b>', '</b>', true),
        ];
        $result = $this->roundTripSegment($elements, 'it.xlf');
        $codes  = $result->getInlineCodes();

        $this->assertCount(2, $codes);
        $this->assertTrue($codes[0]->isIsolated);
        $this->assertSame(InlineCodeType::OPENING, $codes[0]->type);
        $this->assertTrue($codes[1]->isIsolated);
        $this->assertSame(InlineCodeType::CLOSING, $codes[1]->type);
    }

    public function test_code_without_display_text_round_trips(): void
    {
        $elements = [new InlineCode('ph1', InlineCodeType::STANDALONE, '<img src="a.png"/>')];
        $result   = $this->roundTripSegment($elements, 'nodisplay.xlf');
        $codes    = $result->getInlineCodes();

        $this->assertNull($codes[0]->displayText);
        $this->assertSame('<img src="a.png"/>', $codes[0]->data);
    }

    // --- skeleton ---

    public function test_skeleton_with_string_keys_round_trips(): void
    {
        $skeleton = ['version' => '1.0', 'source_file' => 'original.txt', 'encoding' => 'UTF-8'];
        $doc      = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', [], $skeleton);
        $doc->addSegmentPair(new SegmentPair(new Segment('s1', ['Text.'])));

        $result = $this->writeAndRead($doc, 'skeleton.xlf');

        $this->assertSame($skeleton, $result->skeleton);
    }

    public function test_skeleton_file_is_created_next_to_xliff(): void
    {
        $doc = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain');
        $doc->addSegmentPair(new SegmentPair(new Segment('s1', ['Text.'])));

        $xliffPath = $this->tmpDir . '/out.xlf';
        (new XliffWriter())->write($doc, $xliffPath);

        $this->assertFileExists($xliffPath . '.skl');
    }

    // --- encoding ---

    public function test_urdu_rtl_text_round_trips(): void
    {
        $urdu = 'یہ پہلا جملہ ہے۔';
        $doc  = new BilingualDocument('en-US', 'ur-PK', 'test.txt', 'text/plain');
        $doc->addSegmentPair(new SegmentPair(
            source: new Segment('s1', [$urdu]),
            target: new Segment('s1', [$urdu]),
            status: SegmentStatus::Translated,
        ));

        $result = $this->writeAndRead($doc, 'urdu.xlf');
        $pairs  = $result->getSegmentPairs();

        $this->assertSame($urdu, $pairs[0]->source->getPlainText());
        $this->assertSame($urdu, $pairs[0]->target->getPlainText());
    }

    public function test_hindi_text_round_trips(): void
    {
        $hindi = 'यह पहला वाक्य है।';
        $doc   = new BilingualDocument('en-US', 'hi-IN', 'test.txt', 'text/plain');
        $doc->addSegmentPair(new SegmentPair(
            source: new Segment('s1', [$hindi]),
            target: new Segment('s1', [$hindi]),
            status: SegmentStatus::Translated,
        ));

        $result = $this->writeAndRead($doc, 'hindi.xlf');
        $this->assertSame($hindi, $result->getSegmentPairs()[0]->source->getPlainText());
    }

    public function test_xml_special_characters_in_text_are_escaped_and_restored(): void
    {
        $text = 'Use <tags> & "quotes" and \'apostrophes\'.';
        $doc  = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain');
        $doc->addSegmentPair(new SegmentPair(
            source: new Segment('s1', [$text]),
            target: new Segment('s1', [$text]),
            status: SegmentStatus::Translated,
        ));

        $result = $this->writeAndRead($doc, 'special.xlf');
        $this->assertSame($text, $result->getSegmentPairs()[0]->source->getPlainText());
        $this->assertSame($text, $result->getSegmentPairs()[0]->target->getPlainText());
    }

    // --- multiple segments ---

    public function test_multiple_segment_pairs_preserve_order_and_count(): void
    {
        $doc = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain');

        for ($i = 1; $i <= 5; $i++) {
            $doc->addSegmentPair(new SegmentPair(new Segment("s{$i}", ["Sentence {$i}."])));
        }

        $result = $this->writeAndRead($doc, 'multi.xlf');
        $pairs  = $result->getSegmentPairs();

        $this->assertCount(5, $pairs);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame("Sentence {$i}.", $pairs[$i - 1]->source->getPlainText());
        }
    }

    // --- helpers ---

    private function writeAndRead(BilingualDocument $doc, string $filename): BilingualDocument
    {
        $xliffPath = $this->tmpDir . '/' . $filename;
        (new XliffWriter())->write($doc, $xliffPath);
        return (new XliffReader())->read($xliffPath);
    }

    private function roundTripSegment(array $elements, string $filename): Segment
    {
        $doc = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain');
        $doc->addSegmentPair(new SegmentPair(new Segment('s1', $elements)));

        $result = $this->writeAndRead($doc, $filename);
        return $result->getSegmentPairs()[0]->source;
    }
}
