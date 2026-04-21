<?php

declare(strict_types=1);

namespace CatFramework\FilterHtml\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Exception\FilterException;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\FilterHtml\HtmlFilter;
use PHPUnit\Framework\TestCase;

class HtmlFilterTest extends TestCase
{
    private HtmlFilter $filter;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->filter = new HtmlFilter();
        $this->tmpDir = sys_get_temp_dir();
    }

    // --- supports / getSupportedExtensions ---

    public function test_supports_html_and_htm(): void
    {
        $this->assertTrue($this->filter->supports('page.html'));
        $this->assertTrue($this->filter->supports('page.htm'));
    }

    public function test_supports_is_case_insensitive(): void
    {
        $this->assertTrue($this->filter->supports('page.HTML'));
    }

    public function test_supports_rejects_other_extensions(): void
    {
        $this->assertFalse($this->filter->supports('doc.txt'));
        $this->assertFalse($this->filter->supports('doc.docx'));
    }

    public function test_getSupportedExtensions(): void
    {
        $this->assertSame(['.html', '.htm'], $this->filter->getSupportedExtensions());
    }

    // --- extract: basic ---

    public function test_extract_single_paragraph(): void
    {
        $doc = $this->extractHtml('<p>Hello world.</p>');

        $this->assertCount(1, $doc->getSegmentPairs());
        $this->assertSame('Hello world.', $doc->getSegmentPairs()[0]->source->getPlainText());
    }

    public function test_extract_multiple_paragraphs(): void
    {
        $doc = $this->extractHtml('<p>First.</p><p>Second.</p>');

        $this->assertCount(2, $doc->getSegmentPairs());
        $this->assertSame('First.', $doc->getSegmentPairs()[0]->source->getPlainText());
        $this->assertSame('Second.', $doc->getSegmentPairs()[1]->source->getPlainText());
    }

    public function test_extract_headings(): void
    {
        $doc = $this->extractHtml('<h1>Title</h1><p>Body text.</p>');

        $this->assertCount(2, $doc->getSegmentPairs());
        $this->assertSame('Title', $doc->getSegmentPairs()[0]->source->getPlainText());
    }

    public function test_extract_skips_empty_blocks(): void
    {
        $doc = $this->extractHtml('<p></p><p>Only this.</p><p>   </p>');

        $this->assertCount(1, $doc->getSegmentPairs());
        $this->assertSame('Only this.', $doc->getSegmentPairs()[0]->source->getPlainText());
    }

    public function test_extract_paragraphs_inside_div(): void
    {
        $doc = $this->extractHtml('<div><p>First.</p><p>Second.</p></div>');

        $this->assertCount(2, $doc->getSegmentPairs());
    }

    public function test_extract_list_items(): void
    {
        $doc = $this->extractHtml('<ul><li>Item one</li><li>Item two</li></ul>');

        $this->assertCount(2, $doc->getSegmentPairs());
        $this->assertSame('Item one', $doc->getSegmentPairs()[0]->source->getPlainText());
    }

    public function test_extract_target_is_null_for_all_pairs(): void
    {
        $doc = $this->extractHtml('<p>Hello.</p>');

        $this->assertNull($doc->getSegmentPairs()[0]->target);
    }

    public function test_extract_throws_on_missing_file(): void
    {
        $this->expectException(FilterException::class);
        $this->filter->extract('/no/such/file.html', 'en-US', 'hi-IN');
    }

    // --- extract: inline codes ---

    public function test_extract_bold_text_creates_inline_codes(): void
    {
        $doc      = $this->extractHtml('<p>Hello <b>world</b>!</p>');
        $elements = $doc->getSegmentPairs()[0]->source->getElements();

        $this->assertSame('Hello ', $elements[0]);
        $this->assertInstanceOf(\CatFramework\Core\Model\InlineCode::class, $elements[1]);
        $this->assertSame(InlineCodeType::OPENING, $elements[1]->type);
        $this->assertSame('world', $elements[2]);
        $this->assertInstanceOf(\CatFramework\Core\Model\InlineCode::class, $elements[3]);
        $this->assertSame(InlineCodeType::CLOSING, $elements[3]->type);
        $this->assertSame('!', $elements[4]);
    }

    public function test_extract_opening_and_closing_share_same_id(): void
    {
        $doc      = $this->extractHtml('<p><strong>Bold text</strong></p>');
        $elements = $doc->getSegmentPairs()[0]->source->getElements();

        $open  = $elements[0];
        $close = $elements[2];

        $this->assertSame($open->id, $close->id);
        $this->assertSame(InlineCodeType::OPENING, $open->type);
        $this->assertSame(InlineCodeType::CLOSING, $close->type);
    }

    public function test_extract_link_preserves_href_in_data(): void
    {
        $doc      = $this->extractHtml('<p>Visit <a href="https://example.com">here</a>.</p>');
        $elements = $doc->getSegmentPairs()[0]->source->getElements();

        $openCode = $elements[1];
        $this->assertStringContainsString('href', $openCode->data);
        $this->assertStringContainsString('example.com', $openCode->data);
    }

    public function test_extract_br_becomes_standalone_inline_code(): void
    {
        $doc      = $this->extractHtml('<p>Line one<br/>Line two</p>');
        $elements = $doc->getSegmentPairs()[0]->source->getElements();

        $brCode = $elements[1];
        $this->assertSame(InlineCodeType::STANDALONE, $brCode->type);
    }

    public function test_extract_nested_inline_elements(): void
    {
        $doc      = $this->extractHtml('<p><b><i>Bold italic</i></b></p>');
        $elements = $doc->getSegmentPairs()[0]->source->getElements();

        // b open, i open, text, i close, b close
        $this->assertCount(5, $elements);
        $this->assertSame(InlineCodeType::OPENING, $elements[0]->type); // <b>
        $this->assertSame(InlineCodeType::OPENING, $elements[1]->type); // <i>
        $this->assertSame('Bold italic', $elements[2]);
        $this->assertSame(InlineCodeType::CLOSING, $elements[3]->type); // </i>
        $this->assertSame(InlineCodeType::CLOSING, $elements[4]->type); // </b>
    }

    public function test_extract_plain_text_returns_no_inline_codes(): void
    {
        $doc  = $this->extractHtml('<p>Plain text only.</p>');
        $pair = $doc->getSegmentPairs()[0];

        $this->assertSame([], $pair->source->getInlineCodes());
    }

    // --- rebuild ---

    public function test_rebuild_untranslated_document_round_trips(): void
    {
        $html = '<html><body><p>Hello world.</p><p>Second paragraph.</p></body></html>';
        $doc  = $this->extractHtml($html);
        $out  = $this->tmpPath();

        $this->filter->rebuild($doc, $out);

        $rebuilt = file_get_contents($out);
        $this->assertStringContainsString('Hello world.', $rebuilt);
        $this->assertStringContainsString('Second paragraph.', $rebuilt);
    }

    public function test_rebuild_uses_translated_target(): void
    {
        $doc = $this->extractHtml('<p>Hello world.</p>');
        $doc->getSegmentPairs()[0]->target = new Segment('t1', ['नमस्ते दुनिया।']);
        $out = $this->tmpPath();

        $this->filter->rebuild($doc, $out);

        $this->assertStringContainsString('नमस्ते दुनिया।', file_get_contents($out));
    }

    public function test_rebuild_falls_back_to_source_for_untranslated(): void
    {
        $doc = $this->extractHtml('<p>First.</p><p>Second.</p>');
        $doc->getSegmentPairs()[0]->target = new Segment('t1', ['पहला।']);
        $out = $this->tmpPath();

        $this->filter->rebuild($doc, $out);

        $rebuilt = file_get_contents($out);
        $this->assertStringContainsString('पहला।', $rebuilt);
        $this->assertStringContainsString('Second.', $rebuilt); // untranslated fallback
    }

    public function test_rebuild_restores_inline_codes_as_html(): void
    {
        $doc  = $this->extractHtml('<p>Hello <b>world</b>!</p>');
        $pair = $doc->getSegmentPairs()[0];

        // Build a translated segment that reorders the bold text
        $elements = $pair->source->getElements();
        $pair->target = new Segment('t1', [
            'नमस्ते ',
            $elements[1], // <b> opening
            'दुनिया',
            $elements[3], // </b> closing
            '!',
        ]);
        $out = $this->tmpPath();

        $this->filter->rebuild($doc, $out);

        $rebuilt = file_get_contents($out);
        $this->assertStringContainsString('<b>दुनिया</b>', $rebuilt);
    }

    // --- helpers ---

    private function extractHtml(string $html): BilingualDocument
    {
        $path = $this->tmpPath('.html');
        file_put_contents($path, $html);
        return $this->filter->extract($path, 'en-US', 'hi-IN');
    }

    private function tmpPath(string $ext = '.html'): string
    {
        return $this->tmpDir . '/cat_html_test_' . uniqid() . $ext;
    }
}
