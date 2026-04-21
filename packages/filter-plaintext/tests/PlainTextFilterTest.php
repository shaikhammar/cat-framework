<?php

declare(strict_types=1);

namespace CatFramework\FilterPlaintext\Tests;

use CatFramework\Core\Exception\FilterException;
use CatFramework\Core\Model\Segment;
use CatFramework\FilterPlaintext\PlainTextFilter;
use PHPUnit\Framework\TestCase;

class PlainTextFilterTest extends TestCase
{
    private PlainTextFilter $filter;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->filter = new PlainTextFilter();
        $this->tmpDir = sys_get_temp_dir();
    }

    // --- supports / getSupportedExtensions ---

    public function test_supports_txt_file(): void
    {
        $this->assertTrue($this->filter->supports('document.txt'));
    }

    public function test_supports_is_case_insensitive(): void
    {
        $this->assertTrue($this->filter->supports('document.TXT'));
    }

    public function test_supports_rejects_other_extensions(): void
    {
        $this->assertFalse($this->filter->supports('document.html'));
        $this->assertFalse($this->filter->supports('document.docx'));
    }

    public function test_getSupportedExtensions_returns_txt(): void
    {
        $this->assertSame(['.txt'], $this->filter->getSupportedExtensions());
    }

    // --- extract: basic ---

    public function test_extract_splits_on_double_newline(): void
    {
        $path = $this->writeTmp("Hello world.\n\nSecond paragraph.");
        $doc  = $this->filter->extract($path, 'en-US', 'hi-IN');

        $this->assertCount(2, $doc->getSegmentPairs());
        $this->assertSame('Hello world.', $doc->getSegmentPairs()[0]->source->getPlainText());
        $this->assertSame('Second paragraph.', $doc->getSegmentPairs()[1]->source->getPlainText());
    }

    public function test_extract_target_is_null_for_all_pairs(): void
    {
        $path = $this->writeTmp("Para one.\n\nPara two.");
        $doc  = $this->filter->extract($path, 'en-US', 'hi-IN');

        foreach ($doc->getSegmentPairs() as $pair) {
            $this->assertNull($pair->target);
        }
    }

    public function test_extract_sets_document_metadata(): void
    {
        $path = $this->writeTmp("Hello.\n\nWorld.");
        $doc  = $this->filter->extract($path, 'en-US', 'hi-IN');

        $this->assertSame('en-US', $doc->sourceLanguage);
        $this->assertSame('hi-IN', $doc->targetLanguage);
        $this->assertSame('text/plain', $doc->mimeType);
        $this->assertSame(basename($path), $doc->originalFile);
    }

    public function test_extract_skips_whitespace_only_paragraphs(): void
    {
        // Leading blank lines before the first paragraph
        $path = $this->writeTmp("\n\nPara one.\n\nPara two.");
        $doc  = $this->filter->extract($path, 'en-US', 'hi-IN');

        $this->assertCount(2, $doc->getSegmentPairs());
    }

    public function test_extract_three_or_more_newlines_between_paragraphs(): void
    {
        $path = $this->writeTmp("Para one.\n\n\nPara two.");
        $doc  = $this->filter->extract($path, 'en-US', 'hi-IN');

        $this->assertCount(2, $doc->getSegmentPairs());
    }

    public function test_extract_handles_windows_line_endings(): void
    {
        $path = $this->writeTmp("Para one.\r\n\r\nPara two.");
        $doc  = $this->filter->extract($path, 'en-US', 'hi-IN');

        $this->assertCount(2, $doc->getSegmentPairs());
        $this->assertSame('Para one.', $doc->getSegmentPairs()[0]->source->getPlainText());
    }

    public function test_extract_throws_on_missing_file(): void
    {
        $this->expectException(FilterException::class);
        $this->filter->extract('/no/such/file.txt', 'en-US', 'hi-IN');
    }

    // --- rebuild ---

    public function test_rebuild_exact_reconstruction_when_untranslated(): void
    {
        $original = "Para one.\n\nPara two.\n\nPara three.\n";
        $path     = $this->writeTmp($original);
        $doc      = $this->filter->extract($path, 'en-US', 'hi-IN');

        $out = $this->tmpDir . '/rebuilt_' . uniqid() . '.txt';
        $this->filter->rebuild($doc, $out);

        $this->assertSame($original, file_get_contents($out));
    }

    public function test_rebuild_uses_target_text_when_translated(): void
    {
        $path = $this->writeTmp("Hello.\n\nWorld.");
        $doc  = $this->filter->extract($path, 'en-US', 'hi-IN');

        $doc->getSegmentPairs()[0]->target = new Segment('t1', ['नमस्ते।']);
        $doc->getSegmentPairs()[1]->target = new Segment('t2', ['दुनिया।']);

        $out = $this->tmpDir . '/rebuilt_' . uniqid() . '.txt';
        $this->filter->rebuild($doc, $out);

        $this->assertSame("नमस्ते।\n\nदुनिया।", file_get_contents($out));
    }

    public function test_rebuild_falls_back_to_source_for_untranslated_pairs(): void
    {
        $path = $this->writeTmp("Hello.\n\nWorld.");
        $doc  = $this->filter->extract($path, 'en-US', 'hi-IN');

        // Only translate the first pair; second stays null
        $doc->getSegmentPairs()[0]->target = new Segment('t1', ['नमस्ते।']);

        $out = $this->tmpDir . '/rebuilt_' . uniqid() . '.txt';
        $this->filter->rebuild($doc, $out);

        $this->assertSame("नमस्ते।\n\nWorld.", file_get_contents($out));
    }

    public function test_rebuild_preserves_triple_newline_separator(): void
    {
        $original = "Para one.\n\n\nPara two.";
        $path     = $this->writeTmp($original);
        $doc      = $this->filter->extract($path, 'en-US', 'hi-IN');

        $out = $this->tmpDir . '/rebuilt_' . uniqid() . '.txt';
        $this->filter->rebuild($doc, $out);

        $this->assertSame($original, file_get_contents($out));
    }

    // --- helpers ---

    private function writeTmp(string $content): string
    {
        $path = $this->tmpDir . '/cat_test_' . uniqid() . '.txt';
        file_put_contents($path, $content);
        return $path;
    }
}
