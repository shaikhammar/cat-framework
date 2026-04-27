<?php

declare(strict_types=1);

namespace CatFramework\FilterXml\Tests;

use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\FilterXml\XmlFilter;
use PHPUnit\Framework\TestCase;

class XmlFilterTest extends TestCase
{
    private XmlFilter $filter;
    private string    $tmpDir;

    protected function setUp(): void
    {
        $this->filter = new XmlFilter();
        $this->tmpDir = sys_get_temp_dir() . '/xml-filter-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        rmdir($this->tmpDir);
    }

    // ── supports() ───────────────────────────────────────────────────────────

    public function test_supports_xml_extension(): void
    {
        self::assertTrue($this->filter->supports('strings.xml'));
        self::assertTrue($this->filter->supports('/path/to/FILE.XML'));
        self::assertFalse($this->filter->supports('strings.html'));
        self::assertFalse($this->filter->supports('strings.xliff'));
    }

    public function test_get_supported_extensions(): void
    {
        self::assertSame(['.xml'], $this->filter->getSupportedExtensions());
    }

    // ── extract() ────────────────────────────────────────────────────────────

    public function test_extract_yields_three_segments_from_simple_xml(): void
    {
        $doc = $this->filter->extract($this->fixture('simple.xml'), 'en', 'fr');

        self::assertCount(3, $doc->getSegmentPairs());
    }

    public function test_extracted_source_texts(): void
    {
        $doc   = $this->filter->extract($this->fixture('simple.xml'), 'en', 'fr');
        $texts = array_map(fn($p) => $p->source->getPlainText(), $doc->getSegmentPairs());

        self::assertContains('Hello world',  $texts);
        self::assertContains('Save changes', $texts);
        self::assertContains('Cancel',       $texts);
    }

    public function test_whitespace_only_nodes_are_not_extracted(): void
    {
        $doc = $this->filter->extract($this->fixture('simple.xml'), 'en', 'fr');

        foreach ($doc->getSegmentPairs() as $pair) {
            self::assertNotSame('', trim($pair->source->getPlainText()));
        }
    }

    public function test_nested_container_elements_recurse(): void
    {
        $doc   = $this->filter->extract($this->fixture('inline_markup.xml'), 'en', 'fr');
        $texts = array_map(fn($p) => $p->source->getPlainText(), $doc->getSegmentPairs());

        self::assertContains('First item',  $texts);
        self::assertContains('Second item', $texts);
    }

    public function test_inline_elements_become_inline_codes(): void
    {
        $doc   = $this->filter->extract($this->fixture('inline_markup.xml'), 'en', 'fr');
        $pairs = $doc->getSegmentPairs();

        // Find the "Hello <b>world</b>" segment
        $greetingPair = null;
        foreach ($pairs as $pair) {
            if (str_contains($pair->source->getPlainText(), 'Hello')) {
                $greetingPair = $pair;
                break;
            }
        }

        self::assertNotNull($greetingPair);
        $elements = $greetingPair->source->getElements();
        $codes    = array_filter($elements, fn($e) => $e instanceof InlineCode);
        self::assertNotEmpty($codes);
    }

    public function test_skeleton_contains_tokens(): void
    {
        $doc = $this->filter->extract($this->fixture('simple.xml'), 'en', 'fr');

        $skeleton = $doc->skeleton['xml'];
        foreach ($doc->skeleton['seg_map'] as $token) {
            self::assertStringContainsString($token, $skeleton);
        }
    }

    public function test_skeleton_is_valid_xml(): void
    {
        $doc = $this->filter->extract($this->fixture('simple.xml'), 'en', 'fr');

        $dom = new \DOMDocument();
        // Tokens like {{SEG:001}} are not valid XML text in strict mode,
        // but they are valid XML character data, so loadXML should succeed.
        $result = $dom->loadXML($doc->skeleton['xml']);
        self::assertTrue($result, 'Skeleton must be parseable XML');
    }

    // ── rebuild() ────────────────────────────────────────────────────────────

    public function test_roundtrip_produces_translated_xml(): void
    {
        $doc          = $this->filter->extract($this->fixture('simple.xml'), 'en', 'fr');
        $pairs        = $doc->getSegmentPairs();
        $translations = ['Bonjour le monde', 'Enregistrer les modifications', 'Annuler'];

        foreach ($pairs as $i => $pair) {
            $pair->target = new Segment($pair->source->id . '-t', [$translations[$i]]);
        }

        $out = $this->tmpDir . '/output.xml';
        $this->filter->rebuild($doc, $out);

        $result = file_get_contents($out);
        self::assertStringContainsString('Bonjour le monde',                  $result);
        self::assertStringContainsString('Enregistrer les modifications',     $result);
        self::assertStringContainsString('Annuler',                           $result);
    }

    public function test_rebuild_fallback_to_source_when_no_target(): void
    {
        $doc = $this->filter->extract($this->fixture('simple.xml'), 'en', 'fr');

        $out = $this->tmpDir . '/fallback.xml';
        $this->filter->rebuild($doc, $out);

        $result = file_get_contents($out);
        self::assertStringContainsString('Hello world',  $result);
        self::assertStringContainsString('Save changes', $result);
    }

    public function test_rebuild_escapes_special_xml_characters(): void
    {
        $doc   = $this->filter->extract($this->fixture('simple.xml'), 'en', 'fr');
        $first = $doc->getSegmentPairs()[0];
        $first->target = new Segment($first->source->id . '-t', ['<Bonjour & monde>']);

        $out = $this->tmpDir . '/escaped.xml';
        $this->filter->rebuild($doc, $out);

        $result = file_get_contents($out);
        self::assertStringContainsString('&lt;Bonjour &amp; monde&gt;', $result);
    }

    public function test_output_is_valid_xml_after_rebuild(): void
    {
        $doc   = $this->filter->extract($this->fixture('simple.xml'), 'en', 'fr');
        $pairs = $doc->getSegmentPairs();

        foreach ($pairs as $pair) {
            $pair->target = new Segment($pair->source->id . '-t', ['Translation']);
        }

        $out = $this->tmpDir . '/valid.xml';
        $this->filter->rebuild($doc, $out);

        $dom    = new \DOMDocument();
        $result = $dom->loadXML(file_get_contents($out));
        self::assertTrue($result, 'Output must be valid XML');
    }

    public function test_extract_throws_on_missing_file(): void
    {
        $this->expectException(\CatFramework\Core\Exception\FilterException::class);
        $this->filter->extract('/nonexistent/path/strings.xml', 'en', 'fr');
    }

    public function test_extract_throws_on_invalid_xml(): void
    {
        $invalid = $this->tmpDir . '/invalid.xml';
        file_put_contents($invalid, '<root><unclosed>');

        $this->expectException(\CatFramework\Core\Exception\FilterException::class);
        $this->filter->extract($invalid, 'en', 'fr');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function fixture(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }
}
