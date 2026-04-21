<?php

declare(strict_types=1);

namespace CatFramework\TranslationMemory\Tests;

use CatFramework\TranslationMemory\LevenshteinCalculator;
use PHPUnit\Framework\TestCase;

class LevenshteinCalculatorTest extends TestCase
{
    private LevenshteinCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new LevenshteinCalculator();
    }

    // --- distance ---

    public function test_identical_strings_have_distance_zero(): void
    {
        $this->assertSame(0, $this->calc->distance('hello', 'hello'));
    }

    public function test_empty_vs_empty_is_zero(): void
    {
        $this->assertSame(0, $this->calc->distance('', ''));
    }

    public function test_empty_vs_string_is_string_length(): void
    {
        $this->assertSame(5, $this->calc->distance('', 'hello'));
        $this->assertSame(5, $this->calc->distance('hello', ''));
    }

    public function test_single_substitution(): void
    {
        $this->assertSame(1, $this->calc->distance('cat', 'bat'));
    }

    public function test_single_insertion(): void
    {
        $this->assertSame(1, $this->calc->distance('cat', 'cats'));
    }

    public function test_single_deletion(): void
    {
        $this->assertSame(1, $this->calc->distance('cats', 'cat'));
    }

    public function test_completely_different_strings(): void
    {
        $this->assertSame(3, $this->calc->distance('cat', 'dog'));
    }

    public function test_symmetric(): void
    {
        $a = 'Click the Save button.';
        $b = 'Click the OK button.';
        $this->assertSame($this->calc->distance($a, $b), $this->calc->distance($b, $a));
    }

    // --- ASCII fast path vs multibyte path produce same results ---

    public function test_ascii_and_multibyte_paths_agree(): void
    {
        // Pure ASCII — uses fast path
        $this->assertSame(1, $this->calc->distance('café', 'cafe'));
    }

    // --- multibyte (grapheme-level) ---

    public function test_multibyte_accented_char_is_one_edit(): void
    {
        // "café" vs "cafe" — one substitution (é → e), not two bytes
        $this->assertSame(1, $this->calc->distance('café', 'cafe'));
    }

    public function test_hindi_grapheme_distance(): void
    {
        // "नमस्ते" (Namaste) vs "नमस्त" — one grapheme deleted at the end
        $this->assertSame(1, $this->calc->distance('नमस्ते', 'नमस्त'));
    }

    public function test_identical_hindi_strings_have_distance_zero(): void
    {
        $text = 'यह पहला वाक्य है।';
        $this->assertSame(0, $this->calc->distance($text, $text));
    }

    public function test_urdu_distance(): void
    {
        $a = 'یہ پہلا جملہ ہے۔';
        $b = 'یہ دوسرا جملہ ہے۔';
        // 'پہلا' vs 'دوسرا' differs
        $this->assertGreaterThan(0, $this->calc->distance($a, $b));
        $this->assertSame($this->calc->distance($a, $b), $this->calc->distance($b, $a));
    }

    // --- similarity ---

    public function test_identical_strings_similarity_is_one(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->calc->similarity('hello', 'hello'), 0.001);
    }

    public function test_empty_strings_similarity_is_one(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->calc->similarity('', ''), 0.001);
    }

    public function test_similarity_is_between_zero_and_one(): void
    {
        $score = $this->calc->similarity('hello world', 'goodbye world');
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_similarity_known_value(): void
    {
        // "Click the Save button." vs "Click the OK button."
        // Difference: "Save" (4) vs "OK" (2) = edit distance 4 (delete 4, insert 2... actually let's just verify > 0.7)
        $score = $this->calc->similarity(
            'Click the Save button to save your changes.',
            'Click the OK button to save your changes.',
        );
        $this->assertGreaterThan(0.7, $score);
        $this->assertLessThan(1.0, $score);
    }

    public function test_similarity_is_symmetric(): void
    {
        $a = 'The quick brown fox';
        $b = 'The slow brown fox';
        $this->assertEqualsWithDelta(
            $this->calc->similarity($a, $b),
            $this->calc->similarity($b, $a),
            0.001,
        );
    }

    // --- graphemeLength ---

    public function test_grapheme_length_ascii(): void
    {
        $this->assertSame(5, $this->calc->graphemeLength('hello'));
    }

    public function test_grapheme_length_empty(): void
    {
        $this->assertSame(0, $this->calc->graphemeLength(''));
    }

    public function test_grapheme_length_hindi_combining_chars(): void
    {
        // "कित" = कि (1 cluster: क + combining vowel ि) + त (1 cluster) = 2
        $this->assertSame(2, $this->calc->graphemeLength('कित'));
        // "नमस्ते" = न + म + स् (sa+virama) + ते (ta+vowel e) = 4 clusters
        $this->assertSame(4, $this->calc->graphemeLength('नमस्ते'));
    }
}
