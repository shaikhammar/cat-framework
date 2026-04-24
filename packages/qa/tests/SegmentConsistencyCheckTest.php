<?php

declare(strict_types=1);

namespace CatFramework\Qa\Tests;

use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Qa\Check\SegmentConsistencyCheck;
use CatFramework\Qa\QualityRunner;
use PHPUnit\Framework\TestCase;

final class SegmentConsistencyCheckTest extends TestCase
{
    private function makePair(string $sourceId, string $sourceText, ?string $targetText): SegmentPair
    {
        $source = new Segment($sourceId, [$sourceText]);
        $target = $targetText !== null ? new Segment('t_' . $sourceId, [$targetText]) : null;
        return new SegmentPair($source, $target);
    }

    private function makeDoc(array $pairs): BilingualDocument
    {
        $doc = new BilingualDocument('en', 'fr', 'test.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        foreach ($pairs as $pair) {
            $doc->addSegmentPair($pair);
        }
        return $doc;
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_no_issues_when_all_translations_consistent(): void
    {
        $check = new SegmentConsistencyCheck();

        $doc = $this->makeDoc([
            $this->makePair('s1', 'Hello', 'Bonjour'),
            $this->makePair('s2', 'Hello', 'Bonjour'),
            $this->makePair('s3', 'World', 'Monde'),
        ]);

        $issues = $check->checkDocument($doc, 'en', 'fr');

        $this->assertCount(0, $issues);
    }

    // -------------------------------------------------------------------------
    // Basic inconsistency
    // -------------------------------------------------------------------------

    public function test_flags_inconsistent_translations_of_same_source(): void
    {
        $check = new SegmentConsistencyCheck();

        $doc = $this->makeDoc([
            $this->makePair('s1', 'Submit', 'Soumettre'),
            $this->makePair('s2', 'Submit', 'Envoyer'),   // different translation
            $this->makePair('s3', 'Cancel', 'Annuler'),
        ]);

        $issues = $check->checkDocument($doc, 'en', 'fr');

        // Both s1 and s2 should be flagged
        $this->assertCount(2, $issues);

        $segmentIds = array_map(fn($i) => $i->segmentId, $issues);
        $this->assertContains('s1', $segmentIds);
        $this->assertContains('s2', $segmentIds);

        foreach ($issues as $issue) {
            $this->assertSame('segment_consistency', $issue->checkId);
            $this->assertSame(QualitySeverity::WARNING, $issue->severity);
        }
    }

    public function test_flags_all_pairs_involved_in_inconsistency(): void
    {
        $check = new SegmentConsistencyCheck();

        // "Save" translated three different ways
        $doc = $this->makeDoc([
            $this->makePair('s1', 'Save', 'Sauvegarder'),
            $this->makePair('s2', 'Save', 'Enregistrer'),
            $this->makePair('s3', 'Save', 'Sauver'),
        ]);

        $issues = $check->checkDocument($doc, 'en', 'fr');

        $this->assertCount(3, $issues);

        $segmentIds = array_map(fn($i) => $i->segmentId, $issues);
        $this->assertContains('s1', $segmentIds);
        $this->assertContains('s2', $segmentIds);
        $this->assertContains('s3', $segmentIds);
    }

    // -------------------------------------------------------------------------
    // Skipping untranslated pairs
    // -------------------------------------------------------------------------

    public function test_skips_pairs_with_no_target(): void
    {
        $check = new SegmentConsistencyCheck();

        $doc = $this->makeDoc([
            $this->makePair('s1', 'Hello', 'Bonjour'),
            $this->makePair('s2', 'Hello', null),  // untranslated — should not cause inconsistency
        ]);

        $issues = $check->checkDocument($doc, 'en', 'fr');

        $this->assertCount(0, $issues);
    }

    // -------------------------------------------------------------------------
    // Normalisation
    // -------------------------------------------------------------------------

    public function test_normalises_source_whitespace_before_grouping(): void
    {
        $check = new SegmentConsistencyCheck();

        // "Hello" and "  Hello  " should be treated as the same source
        $doc = $this->makeDoc([
            $this->makePair('s1', 'Hello', 'Bonjour'),
            $this->makePair('s2', '  Hello  ', 'Salut'),  // leading/trailing spaces in source
        ]);

        $issues = $check->checkDocument($doc, 'en', 'fr');

        // Should detect inconsistency: same normalised source, different targets
        $this->assertCount(2, $issues);
    }

    // -------------------------------------------------------------------------
    // QualityRunner integration
    // -------------------------------------------------------------------------

    public function test_runner_runs_document_checks_via_run_on_document(): void
    {
        $runner = new QualityRunner();
        $runner->registerDocumentCheck(new SegmentConsistencyCheck());

        $doc = $this->makeDoc([
            $this->makePair('s1', 'Submit', 'Soumettre'),
            $this->makePair('s2', 'Submit', 'Envoyer'),
        ]);

        $issues = $runner->runOnDocument($doc);

        $this->assertCount(2, $issues);
    }

    public function test_runner_document_checks_isolated_from_per_pair_run(): void
    {
        $runner = new QualityRunner();
        $runner->registerDocumentCheck(new SegmentConsistencyCheck());

        $doc = $this->makeDoc([
            $this->makePair('s1', 'Submit', 'Soumettre'),
            $this->makePair('s2', 'Submit', 'Envoyer'),
        ]);

        // run() (per-pair) must not include document-check issues
        $perPairIssues = $runner->run($doc);
        $this->assertCount(0, $perPairIssues);

        // runOnDocument() must return document-check issues
        $docIssues = $runner->runOnDocument($doc);
        $this->assertCount(2, $docIssues);
    }
}
