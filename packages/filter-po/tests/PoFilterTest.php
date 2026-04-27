<?php

declare(strict_types=1);

namespace CatFramework\FilterPo\Tests;

use CatFramework\FilterPo\PoFilter;
use PHPUnit\Framework\TestCase;

class PoFilterTest extends TestCase
{
    private PoFilter $filter;
    private string   $tmpDir;

    protected function setUp(): void
    {
        $this->filter = new PoFilter();
        $this->tmpDir = sys_get_temp_dir() . '/po-filter-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        rmdir($this->tmpDir);
    }

    // ── supports() ───────────────────────────────────────────────────────────

    public function test_supports_po_extension(): void
    {
        self::assertTrue($this->filter->supports('messages.po'));
        self::assertTrue($this->filter->supports('/path/to/FILE.PO'));
        self::assertFalse($this->filter->supports('messages.pot'));
        self::assertFalse($this->filter->supports('messages.txt'));
    }

    public function test_get_supported_extensions(): void
    {
        self::assertSame(['.po'], $this->filter->getSupportedExtensions());
    }

    // ── extract() ────────────────────────────────────────────────────────────

    public function test_extract_yields_three_segments_from_simple_po(): void
    {
        $doc = $this->filter->extract(
            $this->fixture('simple.po'),
            'en',
            'fr',
        );

        self::assertSame('en', $doc->sourceLanguage);
        self::assertSame('fr', $doc->targetLanguage);
        self::assertCount(3, $doc->getSegmentPairs());
    }

    public function test_extracted_segment_source_texts(): void
    {
        $doc   = $this->filter->extract($this->fixture('simple.po'), 'en', 'fr');
        $pairs = $doc->getSegmentPairs();

        self::assertSame('Hello world',  $pairs[0]->source->getPlainText());
        self::assertSame('Save changes', $pairs[1]->source->getPlainText());
        self::assertSame('Cancel',       $pairs[2]->source->getPlainText());
    }

    public function test_fuzzy_entries_are_skipped(): void
    {
        $doc   = $this->filter->extract($this->fixture('fuzzy_and_plural.po'), 'en', 'de');
        $texts = array_map(
            fn($p) => $p->source->getPlainText(),
            $doc->getSegmentPairs(),
        );

        self::assertNotContains('Fuzzy entry', $texts);
        self::assertContains('Normal entry', $texts);
    }

    public function test_plural_entry_extracted_as_singular_msgid(): void
    {
        $doc   = $this->filter->extract($this->fixture('fuzzy_and_plural.po'), 'en', 'de');
        $texts = array_map(fn($p) => $p->source->getPlainText(), $doc->getSegmentPairs());

        // Singular form extracted; plural form ignored
        self::assertContains('One item', $texts);
        self::assertNotContains('%d items', $texts);
    }

    public function test_header_entry_is_skipped(): void
    {
        $doc = $this->filter->extract($this->fixture('simple.po'), 'en', 'fr');

        // Header has empty msgid — it must not appear as a segment
        foreach ($doc->getSegmentPairs() as $pair) {
            self::assertNotSame('', $pair->source->getPlainText());
        }
    }

    public function test_escape_sequences_decoded_in_source(): void
    {
        $doc   = $this->filter->extract($this->fixture('escapes.po'), 'en', 'fr');
        $texts = array_map(fn($p) => $p->source->getPlainText(), $doc->getSegmentPairs());

        self::assertContains("Line one\nLine two", $texts);
        self::assertContains('Say "hello"',        $texts);
        self::assertContains("Tab\there",           $texts);
    }

    public function test_skeleton_contains_token_for_each_segment(): void
    {
        $doc = $this->filter->extract($this->fixture('simple.po'), 'en', 'fr');

        $skeletonStr = implode("\n", $doc->skeleton['lines']);
        foreach ($doc->skeleton['seg_map'] as $token) {
            self::assertStringContainsString($token, $skeletonStr);
        }
    }

    // ── rebuild() ────────────────────────────────────────────────────────────

    public function test_roundtrip_produces_valid_po_with_translations(): void
    {
        $doc     = $this->filter->extract($this->fixture('simple.po'), 'en', 'fr');
        $pairs   = $doc->getSegmentPairs();
        $translations = ['Bonjour le monde', 'Enregistrer les modifications', 'Annuler'];

        foreach ($pairs as $i => $pair) {
            $pair->target = new \CatFramework\Core\Model\Segment($pair->source->id . '-t', [$translations[$i]]);
        }

        $out = $this->tmpDir . '/output.po';
        $this->filter->rebuild($doc, $out);

        $result = file_get_contents($out);
        self::assertStringContainsString('msgstr "Bonjour le monde"',                  $result);
        self::assertStringContainsString('msgstr "Enregistrer les modifications"',     $result);
        self::assertStringContainsString('msgstr "Annuler"',                           $result);
    }

    public function test_rebuild_fallback_to_source_when_no_target(): void
    {
        $doc = $this->filter->extract($this->fixture('simple.po'), 'en', 'fr');

        $out = $this->tmpDir . '/fallback.po';
        $this->filter->rebuild($doc, $out);

        $result = file_get_contents($out);
        self::assertStringContainsString('msgstr "Hello world"',  $result);
        self::assertStringContainsString('msgstr "Save changes"', $result);
    }

    public function test_rebuild_escapes_special_characters_in_translation(): void
    {
        $doc   = $this->filter->extract($this->fixture('simple.po'), 'en', 'fr');
        $first = $doc->getSegmentPairs()[0];
        $first->target = new \CatFramework\Core\Model\Segment($first->source->id . '-t', ["Line one\nLine two"]);

        $out = $this->tmpDir . '/escaped.po';
        $this->filter->rebuild($doc, $out);

        self::assertStringContainsString('msgstr "Line one\\nLine two"', file_get_contents($out));
    }

    public function test_plural_rebuild_puts_translation_in_msgstr_0(): void
    {
        $doc   = $this->filter->extract($this->fixture('fuzzy_and_plural.po'), 'en', 'de');
        $pairs = $doc->getSegmentPairs();

        // Find the plural segment ("One item")
        $pluralPair = null;
        foreach ($pairs as $pair) {
            if ($pair->source->getPlainText() === 'One item') {
                $pluralPair = $pair;
                break;
            }
        }
        self::assertNotNull($pluralPair);

        $pluralPair->target = new \CatFramework\Core\Model\Segment($pluralPair->source->id . '-t', ['Ein Element']);

        $out = $this->tmpDir . '/plural.po';
        $this->filter->rebuild($doc, $out);

        $result = file_get_contents($out);
        self::assertStringContainsString('msgstr[0] "Ein Element"', $result);
        self::assertStringContainsString('msgstr[1] ""', $result);
    }

    public function test_extract_throws_on_missing_file(): void
    {
        $this->expectException(\CatFramework\Core\Exception\FilterException::class);
        $this->filter->extract('/nonexistent/path/messages.po', 'en', 'fr');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function fixture(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }
}
