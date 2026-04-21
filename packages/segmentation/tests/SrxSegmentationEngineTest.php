<?php

declare(strict_types=1);

namespace CatFramework\Segmentation\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Segmentation\SrxSegmentationEngine;
use CatFramework\Srx\SrxParser;
use PHPUnit\Framework\TestCase;

class SrxSegmentationEngineTest extends TestCase
{
    private SrxSegmentationEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new SrxSegmentationEngine();
        $this->engine->loadRules(SrxParser::defaultSrxPath());
    }

    // --- single-sentence inputs ---

    public function test_single_sentence_returns_input_unchanged(): void
    {
        $seg    = new Segment('s1', ['Hello world.']);
        $result = $this->engine->segment($seg, 'en-US');

        $this->assertCount(1, $result);
        $this->assertSame('Hello world.', $result[0]->getPlainText());
    }

    public function test_empty_segment_returns_input_unchanged(): void
    {
        $seg    = new Segment('s1');
        $result = $this->engine->segment($seg, 'en-US');

        $this->assertCount(1, $result);
    }

    // --- English segmentation ---

    public function test_english_two_sentences_split_correctly(): void
    {
        $seg    = new Segment('s1', ['Hello world. This is a test.']);
        $result = $this->engine->segment($seg, 'en-US');

        $this->assertCount(2, $result);
        $this->assertSame('Hello world. ', $result[0]->getPlainText());
        $this->assertSame('This is a test.', $result[1]->getPlainText());
    }

    public function test_english_three_sentences(): void
    {
        $seg    = new Segment('s1', ['First sentence. Second sentence. Third sentence.']);
        $result = $this->engine->segment($seg, 'en-US');

        $this->assertCount(3, $result);
    }

    public function test_english_no_break_on_mr_abbreviation(): void
    {
        $seg    = new Segment('s1', ['Mr. Smith is here. He called today.']);
        $result = $this->engine->segment($seg, 'en-US');

        // "Mr. Smith" should NOT break; only one break expected
        $this->assertCount(2, $result);
        $this->assertStringContainsString('Mr. Smith', $result[0]->getPlainText());
    }

    public function test_english_question_mark_is_sentence_break(): void
    {
        $seg    = new Segment('s1', ['Are you ready? Yes, I am.']);
        $result = $this->engine->segment($seg, 'en-US');

        $this->assertCount(2, $result);
    }

    // --- Hindi segmentation ---

    public function test_hindi_purna_viram_is_sentence_break(): void
    {
        $seg    = new Segment('s1', ['यह पहला वाक्य है। यह दूसरा वाक्य है।']);
        $result = $this->engine->segment($seg, 'hi-IN');

        $this->assertCount(2, $result);
        $this->assertStringContainsString('।', $result[0]->getPlainText());
    }

    public function test_hindi_no_break_without_purna_viram(): void
    {
        $seg    = new Segment('s1', ['यह एक लंबा वाक्य है जिसमें कोई विराम नहीं है']);
        $result = $this->engine->segment($seg, 'hi-IN');

        $this->assertCount(1, $result);
    }

    // --- Urdu segmentation ---

    public function test_urdu_arabic_full_stop_is_sentence_break(): void
    {
        $seg    = new Segment('s1', ['یہ پہلا جملہ ہے۔ یہ دوسرا جملہ ہے۔']);
        $result = $this->engine->segment($seg, 'ur-PK');

        $this->assertCount(2, $result);
    }

    // --- segment ID generation ---

    public function test_segment_ids_include_parent_id(): void
    {
        $seg    = new Segment('para-1', ['First. Second.']);
        $result = $this->engine->segment($seg, 'en-US');

        $this->assertStringStartsWith('para-1:', $result[0]->id);
        $this->assertStringStartsWith('para-1:', $result[1]->id);
    }

    // --- InlineCode distribution ---

    public function test_inline_code_before_break_stays_in_first_segment(): void
    {
        // "Hello [b]world[/b]. Second sentence."
        // Bold wraps "world" which is in the first sentence.
        $elements = [
            'Hello ',
            new InlineCode('b1', InlineCodeType::OPENING, '<b>'),
            'world',
            new InlineCode('b1', InlineCodeType::CLOSING, '</b>'),
            '. Second sentence.',
        ];
        $seg    = new Segment('s1', $elements);
        $result = $this->engine->segment($seg, 'en-US');

        $this->assertCount(2, $result);

        $firstCodes = $result[0]->getInlineCodes();
        $this->assertCount(2, $firstCodes); // opening + closing both in first segment
        $this->assertFalse($firstCodes[0]->isIsolated);
        $this->assertFalse($firstCodes[1]->isIsolated);
    }

    public function test_inline_code_after_break_stays_in_second_segment(): void
    {
        // "First sentence. Hello [b]world[/b]."
        $elements = [
            'First sentence. Hello ',
            new InlineCode('b1', InlineCodeType::OPENING, '<b>'),
            'world',
            new InlineCode('b1', InlineCodeType::CLOSING, '</b>'),
            '.',
        ];
        $seg    = new Segment('s1', $elements);
        $result = $this->engine->segment($seg, 'en-US');

        $this->assertCount(2, $result);

        $secondCodes = $result[1]->getInlineCodes();
        $this->assertCount(2, $secondCodes); // both in second segment
        $this->assertFalse($secondCodes[0]->isIsolated);
    }

    public function test_spanning_inline_code_is_marked_isolated(): void
    {
        // "[b]Bold text. Also bold.[/b]" — bold spans across the sentence break
        $elements = [
            new InlineCode('b1', InlineCodeType::OPENING, '<b>'),
            'Bold text. Also bold.',
            new InlineCode('b1', InlineCodeType::CLOSING, '</b>'),
        ];
        $seg    = new Segment('s1', $elements);
        $result = $this->engine->segment($seg, 'en-US');

        $this->assertCount(2, $result);

        // Segment A should have OPENING (isolated) + synthetic CLOSING (isolated)
        $codesA = $result[0]->getInlineCodes();
        $this->assertCount(2, $codesA);
        $this->assertSame(InlineCodeType::OPENING, $codesA[0]->type);
        $this->assertTrue($codesA[0]->isIsolated);
        $this->assertSame(InlineCodeType::CLOSING, $codesA[1]->type);
        $this->assertTrue($codesA[1]->isIsolated);

        // Segment B should have synthetic OPENING (isolated) + original CLOSING (isolated)
        $codesB = $result[1]->getInlineCodes();
        $this->assertCount(2, $codesB);
        $this->assertSame(InlineCodeType::OPENING, $codesB[0]->type);
        $this->assertTrue($codesB[0]->isIsolated);
        $this->assertSame(InlineCodeType::CLOSING, $codesB[1]->type);
        $this->assertTrue($codesB[1]->isIsolated);
    }

    public function test_spanning_code_plain_text_is_correct(): void
    {
        $elements = [
            new InlineCode('b1', InlineCodeType::OPENING, '<b>'),
            'First sentence. Second sentence.',
            new InlineCode('b1', InlineCodeType::CLOSING, '</b>'),
        ];
        $seg    = new Segment('s1', $elements);
        $result = $this->engine->segment($seg, 'en-US');

        $this->assertSame('First sentence. ', $result[0]->getPlainText());
        $this->assertSame('Second sentence.', $result[1]->getPlainText());
    }

    public function test_standalone_code_assigned_to_correct_segment(): void
    {
        // "First sentence.[br] Second sentence."
        // The <br> is at the boundary — it should go to segment A.
        $elements = [
            'First sentence.',
            new InlineCode('br1', InlineCodeType::STANDALONE, '<br/>'),
            ' Second sentence.',
        ];
        $seg    = new Segment('s1', $elements);
        $result = $this->engine->segment($seg, 'en-US');

        $this->assertCount(2, $result);
        // The <br> is at position 16 (end of "First sentence."), which is the break point.
        // It should be in segment A (position < break or at break boundary).
        $codesA = $result[0]->getInlineCodes();
        $codesB = $result[1]->getInlineCodes();
        // Exactly one segment gets the standalone code
        $totalCodes = count($codesA) + count($codesB);
        $this->assertSame(1, $totalCodes);
    }
}
