<?php

declare(strict_types=1);

namespace CatFramework\Core\Tests\Model;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use PHPUnit\Framework\TestCase;

class SegmentTest extends TestCase
{
    // --- getPlainText ---

    public function test_getPlainText_returns_text_only(): void
    {
        $segment = new Segment('s1', [
            'Hello ',
            new InlineCode('b1', InlineCodeType::OPENING, '<b>', '<b>'),
            'world',
            new InlineCode('b1', InlineCodeType::CLOSING, '</b>', '</b>'),
            '!',
        ]);

        $this->assertSame('Hello world!', $segment->getPlainText());
    }

    public function test_getPlainText_on_empty_segment_returns_empty_string(): void
    {
        $segment = new Segment('s1');

        $this->assertSame('', $segment->getPlainText());
    }

    public function test_getPlainText_with_only_inline_codes_returns_empty_string(): void
    {
        $segment = new Segment('s1', [
            new InlineCode('br1', InlineCodeType::STANDALONE, '<br/>', '<br/>'),
        ]);

        $this->assertSame('', $segment->getPlainText());
    }

    public function test_getPlainText_preserves_internal_whitespace(): void
    {
        $segment = new Segment('s1', ['Hello   world']);

        $this->assertSame('Hello   world', $segment->getPlainText());
    }

    // --- isEmpty ---

    public function test_isEmpty_on_new_segment_returns_true(): void
    {
        $segment = new Segment('s1');

        $this->assertTrue($segment->isEmpty());
    }

    public function test_isEmpty_with_text_returns_false(): void
    {
        $segment = new Segment('s1', ['Hello']);

        $this->assertFalse($segment->isEmpty());
    }

    public function test_isEmpty_with_only_empty_string_elements_returns_true(): void
    {
        $segment = new Segment('s1', ['', '']);

        $this->assertTrue($segment->isEmpty());
    }

    public function test_isEmpty_with_only_inline_codes_returns_true(): void
    {
        $segment = new Segment('s1', [
            new InlineCode('b1', InlineCodeType::OPENING, '<b>'),
            new InlineCode('b1', InlineCodeType::CLOSING, '</b>'),
        ]);

        $this->assertTrue($segment->isEmpty());
    }

    // --- getInlineCodes ---

    public function test_getInlineCodes_returns_only_inline_codes_in_order(): void
    {
        $open  = new InlineCode('b1', InlineCodeType::OPENING, '<b>');
        $close = new InlineCode('b1', InlineCodeType::CLOSING, '</b>');

        $segment = new Segment('s1', ['Hello ', $open, 'world', $close, '!']);

        $this->assertSame([$open, $close], $segment->getInlineCodes());
    }

    public function test_getInlineCodes_on_plain_text_segment_returns_empty_array(): void
    {
        $segment = new Segment('s1', ['Hello world']);

        $this->assertSame([], $segment->getInlineCodes());
    }

    // --- setElements ---

    public function test_setElements_replaces_content(): void
    {
        $segment = new Segment('s1', ['Original']);
        $segment->setElements(['Replaced']);

        $this->assertSame(['Replaced'], $segment->getElements());
    }
}
