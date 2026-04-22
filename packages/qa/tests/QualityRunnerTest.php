<?php

declare(strict_types=1);

namespace CatFramework\Qa\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Qa\Check\DoubleSpaceCheck;
use CatFramework\Qa\Check\EmptyTranslationCheck;
use CatFramework\Qa\Check\NumberConsistencyCheck;
use CatFramework\Qa\Check\TagConsistencyCheck;
use CatFramework\Qa\Check\WhitespaceCheck;
use CatFramework\Qa\QualityRunner;
use PHPUnit\Framework\TestCase;

final class QualityRunnerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeSegment(string $id, string $text, array $codes = []): Segment
    {
        $elements = $codes === [] ? [$text] : $codes;
        return new Segment($id, $elements);
    }

    private function makePair(string $sourceText, ?string $targetText, string $id = 's1'): SegmentPair
    {
        $source = $this->makeSegment($id, $sourceText);
        $target = $targetText !== null ? $this->makeSegment('t1', $targetText) : null;
        return new SegmentPair($source, $target);
    }

    // -------------------------------------------------------------------------
    // EmptyTranslationCheck
    // -------------------------------------------------------------------------

    public function test_empty_translation_flags_untranslated_pair(): void
    {
        $check = new EmptyTranslationCheck();
        $pair = $this->makePair('Hello world', null);

        $issues = $check->check($pair, 'en', 'fr');

        $this->assertCount(1, $issues);
        $this->assertSame('empty_translation', $issues[0]->checkId);
        $this->assertSame(QualitySeverity::ERROR, $issues[0]->severity);
    }

    public function test_empty_translation_passes_when_source_is_also_empty(): void
    {
        $check = new EmptyTranslationCheck();
        $pair = $this->makePair('', null);

        $this->assertCount(0, $check->check($pair, 'en', 'fr'));
    }

    public function test_empty_translation_passes_when_target_present(): void
    {
        $check = new EmptyTranslationCheck();
        $pair = $this->makePair('Hello', 'Bonjour');

        $this->assertCount(0, $check->check($pair, 'en', 'fr'));
    }

    // -------------------------------------------------------------------------
    // TagConsistencyCheck
    // -------------------------------------------------------------------------

    public function test_tag_consistency_flags_missing_tag_in_target(): void
    {
        $check = new TagConsistencyCheck();

        $bold = new InlineCode('1', InlineCodeType::OPENING, '<b>', '<b>');
        $boldClose = new InlineCode('1', InlineCodeType::CLOSING, '</b>', '</b>');

        $source = new Segment('s1', ['Hello ', $bold, 'world', $boldClose]);
        $target = new Segment('t1', ['Bonjour monde']);
        $pair = new SegmentPair($source, $target);

        $issues = $check->check($pair, 'en', 'fr');

        $this->assertCount(1, $issues);
        $this->assertSame('tag_consistency', $issues[0]->checkId);
        $this->assertStringContainsString('<b>', $issues[0]->message);
    }

    public function test_tag_consistency_flags_extra_tag_in_target(): void
    {
        $check = new TagConsistencyCheck();

        $bold = new InlineCode('2', InlineCodeType::OPENING, '<b>', '<b>');

        $source = new Segment('s1', ['Hello world']);
        $target = new Segment('t1', [$bold, 'Bonjour monde']);
        $pair = new SegmentPair($source, $target);

        $issues = $check->check($pair, 'en', 'fr');

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('no match in source', $issues[0]->message);
    }

    public function test_tag_consistency_passes_when_tags_match(): void
    {
        $check = new TagConsistencyCheck();

        $bold = new InlineCode('1', InlineCodeType::OPENING, '<b>', '<b>');
        $boldClose = new InlineCode('1', InlineCodeType::CLOSING, '</b>', '</b>');

        $source = new Segment('s1', [$bold, 'Hello', $boldClose]);
        $target = new Segment('t1', [$bold, 'Bonjour', $boldClose]);
        $pair = new SegmentPair($source, $target);

        $this->assertCount(0, $check->check($pair, 'en', 'fr'));
    }

    public function test_tag_consistency_skips_when_target_is_null(): void
    {
        $check = new TagConsistencyCheck();
        $bold = new InlineCode('1', InlineCodeType::OPENING, '<b>', '<b>');
        $source = new Segment('s1', [$bold, 'Hello']);
        $pair = new SegmentPair($source, null);

        $this->assertCount(0, $check->check($pair, 'en', 'fr'));
    }

    // -------------------------------------------------------------------------
    // NumberConsistencyCheck
    // -------------------------------------------------------------------------

    public function test_number_consistency_flags_missing_number(): void
    {
        $check = new NumberConsistencyCheck();
        $pair = $this->makePair('Invoice total: 1234.56', 'Total facture: 100.00');

        $issues = $check->check($pair, 'en', 'fr');

        $this->assertNotEmpty($issues);
        $this->assertSame('number_mismatch', $issues[0]->checkId);
        $this->assertSame(QualitySeverity::WARNING, $issues[0]->severity);
    }

    public function test_number_consistency_passes_when_numbers_match(): void
    {
        $check = new NumberConsistencyCheck();
        $pair = $this->makePair('The cost is 42 dollars.', 'Le coût est de 42 dollars.');

        $this->assertCount(0, $check->check($pair, 'en', 'fr'));
    }

    public function test_number_consistency_skips_when_no_target(): void
    {
        $check = new NumberConsistencyCheck();
        $pair = $this->makePair('Total: 100', null);

        $this->assertCount(0, $check->check($pair, 'en', 'fr'));
    }

    // -------------------------------------------------------------------------
    // WhitespaceCheck
    // -------------------------------------------------------------------------

    public function test_whitespace_flags_leading_mismatch(): void
    {
        $check = new WhitespaceCheck();
        $pair = $this->makePair(' Hello', 'Bonjour');

        $issues = $check->check($pair, 'en', 'fr');

        $this->assertNotEmpty($issues);
        $this->assertSame('whitespace_mismatch', $issues[0]->checkId);
        $this->assertStringContainsString('Leading', $issues[0]->message);
    }

    public function test_whitespace_flags_trailing_mismatch(): void
    {
        $check = new WhitespaceCheck();
        $pair = $this->makePair('Hello ', 'Bonjour');

        $issues = $check->check($pair, 'en', 'fr');

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('Trailing', $issues[0]->message);
    }

    public function test_whitespace_passes_when_whitespace_matches(): void
    {
        $check = new WhitespaceCheck();
        $pair = $this->makePair(' Hello ', ' Bonjour ');

        $this->assertCount(0, $check->check($pair, 'en', 'fr'));
    }

    // -------------------------------------------------------------------------
    // DoubleSpaceCheck
    // -------------------------------------------------------------------------

    public function test_double_space_flags_consecutive_spaces(): void
    {
        $check = new DoubleSpaceCheck();
        $pair = $this->makePair('Hello world', 'Bonjour  monde');

        $issues = $check->check($pair, 'en', 'fr');

        $this->assertCount(1, $issues);
        $this->assertSame('double_space', $issues[0]->checkId);
        $this->assertSame(QualitySeverity::INFO, $issues[0]->severity);
        $this->assertSame(7, $issues[0]->offset);
    }

    public function test_double_space_passes_when_no_double_spaces(): void
    {
        $check = new DoubleSpaceCheck();
        $pair = $this->makePair('Hello world', 'Bonjour monde');

        $this->assertCount(0, $check->check($pair, 'en', 'fr'));
    }

    // -------------------------------------------------------------------------
    // QualityRunner
    // -------------------------------------------------------------------------

    public function test_runner_aggregates_issues_from_all_checks(): void
    {
        $runner = new QualityRunner();
        $runner->register(new EmptyTranslationCheck());
        $runner->register(new DoubleSpaceCheck());

        $doc = new BilingualDocument('en', 'fr', 'test.txt', 'text/plain');
        $doc->addSegmentPair($this->makePair('Hello', null, 's1'));           // triggers EmptyTranslation
        $doc->addSegmentPair($this->makePair('Hello', 'Bonne  nuit', 's2')); // triggers DoubleSpace

        $issues = $runner->run($doc);

        $this->assertCount(2, $issues);
        $ids = array_map(fn($i) => $i->checkId, $issues);
        $this->assertContains('empty_translation', $ids);
        $this->assertContains('double_space', $ids);
    }

    public function test_runner_returns_empty_when_no_checks_registered(): void
    {
        $runner = new QualityRunner();
        $doc = new BilingualDocument('en', 'fr', 'test.txt', 'text/plain');
        $doc->addSegmentPair($this->makePair('Hello', null));

        $this->assertCount(0, $runner->run($doc));
    }

    public function test_runner_on_pair_returns_issues_for_single_pair(): void
    {
        $runner = new QualityRunner();
        $runner->register(new EmptyTranslationCheck());

        $pair = $this->makePair('Hello', null);
        $issues = $runner->runOnPair($pair, 'en', 'fr');

        $this->assertCount(1, $issues);
    }
}
