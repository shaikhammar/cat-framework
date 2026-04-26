<?php

declare(strict_types=1);

namespace CatFramework\Workflow\Tests;

use CatFramework\Core\Contract\FileFilterInterface;
use CatFramework\Core\Contract\MachineTranslationInterface;
use CatFramework\Core\Contract\TerminologyProviderInterface;
use CatFramework\Core\Contract\TranslationMemoryInterface;
use CatFramework\Core\Enum\MatchType;
use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Enum\SegmentStatus;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\MatchResult;
use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Core\Model\TranslationUnit;
use CatFramework\Qa\QualityRunner;
use CatFramework\Qa\Check\EmptyTranslationCheck;
use CatFramework\Segmentation\SrxSegmentationEngine;
use CatFramework\Workflow\Exception\WorkflowException;
use CatFramework\Workflow\FileFilterRegistry;
use CatFramework\Workflow\WorkflowOptions;
use CatFramework\Workflow\WorkflowRunner;
use CatFramework\Xliff\XliffWriter;
use PHPUnit\Framework\TestCase;

final class WorkflowRunnerTest extends TestCase
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeDoc(array $sourcePhrases): BilingualDocument
    {
        $pairs = [];
        foreach ($sourcePhrases as $i => $text) {
            $pairs[] = new SegmentPair(new Segment("s{$i}", [$text]));
        }
        return new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', $pairs);
    }

    private function makeFilter(BilingualDocument $doc): FileFilterInterface
    {
        return new class($doc) implements FileFilterInterface {
            public function __construct(private readonly BilingualDocument $doc) {}
            public function supports(string $filePath, ?string $mimeType = null): bool { return true; }
            public function extract(string $filePath, string $sourceLanguage, string $targetLanguage): BilingualDocument { return $this->doc; }
            public function rebuild(BilingualDocument $document, string $outputPath): void {}
            public function getSupportedExtensions(): array { return ['.txt']; }
        };
    }

    private function makeRegistry(FileFilterInterface $filter): FileFilterRegistry
    {
        $registry = new FileFilterRegistry();
        $registry->register($filter);
        return $registry;
    }

    private function makeRunner(
        FileFilterRegistry $registry,
        ?TranslationMemoryInterface $tm = null,
        ?MachineTranslationInterface $mt = null,
        ?QualityRunner $qa = null,
        ?WorkflowOptions $options = null,
    ): WorkflowRunner {
        $options ??= WorkflowOptions::defaults();
        $options->writeXliff = false;

        return new WorkflowRunner(
            fileFilterRegistry: $registry,
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: 'en-US',
            translationMemory: $tm,
            terminologyProvider: null,
            mtAdapter: $mt,
            qaRunner: $qa,
            options: $options,
        );
    }

    private function makeTm(float $score): TranslationMemoryInterface
    {
        return new class($score) implements TranslationMemoryInterface {
            public function __construct(private readonly float $score) {}

            public function lookup(Segment $source, string $sourceLanguage, string $targetLanguage, float $minScore = 0.7, int $maxResults = 5): array
            {
                if ($this->score < $minScore) {
                    return [];
                }
                return [new MatchResult(
                    translationUnit: new TranslationUnit(
                        source: $source,
                        target: new Segment('t-' . $source->id, ['[TM] ' . $source->getPlainText()]),
                        sourceLanguage: $sourceLanguage,
                        targetLanguage: $targetLanguage,
                        createdAt: new \DateTimeImmutable(),
                    ),
                    score: $this->score,
                    type: $this->score >= 1.0 ? MatchType::EXACT : MatchType::FUZZY,
                )];
            }

            public function store(TranslationUnit $unit): void {}
            public function import(string $tmxFilePath): int { return 0; }
            public function export(string $tmxFilePath): int { return 0; }
        };
    }

    private function makeMt(): MachineTranslationInterface
    {
        return new class implements MachineTranslationInterface {
            public function translate(Segment $source, string $sourceLanguage, string $targetLanguage): Segment
            {
                return new Segment('mt-' . $source->id, ['[MT] ' . $source->getPlainText()]);
            }

            public function translateBatch(array $sources, string $sourceLanguage, string $targetLanguage): array
            {
                return array_map(fn($s) => $this->translate($s, $sourceLanguage, $targetLanguage), $sources);
            }

            public function getProviderId(): string { return 'stub-mt'; }
        };
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_exact_tm_match_locks_segment(): void
    {
        $doc = $this->makeDoc(['Hello world']);
        $runner = $this->makeRunner(
            registry: $this->makeRegistry($this->makeFilter($doc)),
            tm: $this->makeTm(1.0),
        );

        $result = $runner->process('/tmp/test.txt', 'fr-FR');
        $pairs  = $result->document->getSegmentPairs();

        $this->assertSame(SegmentStatus::Translated, $pairs[0]->status);
        $this->assertTrue($pairs[0]->isLocked);
        $this->assertSame('[TM] Hello world', $pairs[0]->target->getPlainText());
    }

    public function test_fuzzy_tm_match_translates_but_does_not_lock(): void
    {
        $doc = $this->makeDoc(['Hello world']);
        $runner = $this->makeRunner(
            registry: $this->makeRegistry($this->makeFilter($doc)),
            tm: $this->makeTm(0.85),
        );

        $result = $runner->process('/tmp/test.txt', 'fr-FR');
        $pairs  = $result->document->getSegmentPairs();

        $this->assertSame(SegmentStatus::Draft, $pairs[0]->status);
        $this->assertFalse($pairs[0]->isLocked);
    }

    public function test_mt_fill_triggered_when_no_tm_match_and_threshold_exceeded(): void
    {
        $doc = $this->makeDoc(['Hello world']);

        $options = WorkflowOptions::defaults();
        $options->mtFillThreshold = 1.0; // fill all unmatched
        $options->writeXliff = false;

        $runner = new WorkflowRunner(
            fileFilterRegistry: $this->makeRegistry($this->makeFilter($doc)),
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: 'en-US',
            translationMemory: $this->makeTm(0.0), // returns [] (below minScore 0.7)
            mtAdapter: $this->makeMt(),
            options: $options,
        );

        $result = $runner->process('/tmp/test.txt', 'fr-FR');
        $pairs  = $result->document->getSegmentPairs();

        $this->assertSame('[MT] Hello world', $pairs[0]->target->getPlainText());
        $this->assertSame(1, $result->matchStats->mt);
        $this->assertSame(0, $result->matchStats->unmatched);
    }

    public function test_mt_skipped_when_tm_score_meets_threshold(): void
    {
        $doc = $this->makeDoc(['Hello world']);

        $options = WorkflowOptions::defaults();
        $options->mtFillThreshold = 0.5; // only fill below 50%
        $options->writeXliff = false;

        // TM returns 0.85 match — above mtFillThreshold, so MT should NOT fire
        $runner = new WorkflowRunner(
            fileFilterRegistry: $this->makeRegistry($this->makeFilter($doc)),
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: 'en-US',
            translationMemory: $this->makeTm(0.85),
            mtAdapter: $this->makeMt(),
            options: $options,
        );

        $result = $runner->process('/tmp/test.txt', 'fr-FR');

        $this->assertSame(0, $result->matchStats->mt);
        $this->assertSame(1, $result->matchStats->fuzzy);
        $this->assertStringContainsString('[TM]', $result->document->getSegmentPairs()[0]->target->getPlainText());
    }

    public function test_match_stats_counts_correctly(): void
    {
        $exactDoc = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', [
            new SegmentPair(new Segment('s0', ['exact segment'])),
            new SegmentPair(new Segment('s1', ['fuzzy segment'])),
            new SegmentPair(new Segment('s2', ['unmatched segment'])),
        ]);

        $tm = new class implements TranslationMemoryInterface {
            public function lookup(Segment $source, string $sourceLanguage, string $targetLanguage, float $minScore = 0.7, int $maxResults = 5): array
            {
                $text = $source->getPlainText();
                if ($text === 'exact segment') {
                    return [new MatchResult(
                        translationUnit: new TranslationUnit(
                            source: $source,
                            target: new Segment('t0', ['exact translation']),
                            sourceLanguage: $sourceLanguage,
                            targetLanguage: $targetLanguage,
                            createdAt: new \DateTimeImmutable(),
                        ),
                        score: 1.0,
                        type: MatchType::EXACT,
                    )];
                }
                if ($text === 'fuzzy segment') {
                    return [new MatchResult(
                        translationUnit: new TranslationUnit(
                            source: $source,
                            target: new Segment('t1', ['fuzzy translation']),
                            sourceLanguage: $sourceLanguage,
                            targetLanguage: $targetLanguage,
                            createdAt: new \DateTimeImmutable(),
                        ),
                        score: 0.8,
                        type: MatchType::FUZZY,
                    )];
                }
                return [];
            }
            public function store(TranslationUnit $unit): void {}
            public function import(string $tmxFilePath): int { return 0; }
            public function export(string $tmxFilePath): int { return 0; }
        };

        $registry = $this->makeRegistry($this->makeFilter($exactDoc));
        $runner   = $this->makeRunner(registry: $registry, tm: $tm);
        $result   = $runner->process('/tmp/test.txt', 'fr-FR');

        $this->assertSame(1, $result->matchStats->exact);
        $this->assertSame(1, $result->matchStats->fuzzy);
        $this->assertSame(0, $result->matchStats->mt);
        $this->assertSame(1, $result->matchStats->unmatched);
    }

    public function test_qa_throws_workflow_exception_when_severity_breached(): void
    {
        $doc = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', [
            new SegmentPair(new Segment('s0', ['Hello'])), // no target → EmptyTranslationCheck ERROR
        ]);

        $qaRunner = new QualityRunner();
        $qaRunner->register(new EmptyTranslationCheck());

        $options = WorkflowOptions::defaults();
        $options->writeXliff = false;
        $options->qaFailOnSeverity = 'error';

        $runner = new WorkflowRunner(
            fileFilterRegistry: $this->makeRegistry($this->makeFilter($doc)),
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: 'en-US',
            qaRunner: $qaRunner,
            options: $options,
        );

        $this->expectException(WorkflowException::class);
        $runner->process('/tmp/test.txt', 'fr-FR');
    }

    public function test_qa_does_not_throw_when_severity_below_threshold(): void
    {
        $doc = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', [
            new SegmentPair(new Segment('s0', ['Hello'])), // no target → ERROR
        ]);

        $qaRunner = new QualityRunner();
        $qaRunner->register(new EmptyTranslationCheck());

        $options = WorkflowOptions::defaults();
        $options->writeXliff = false;
        $options->qaFailOnSeverity = null; // never throw

        $runner = new WorkflowRunner(
            fileFilterRegistry: $this->makeRegistry($this->makeFilter($doc)),
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: 'en-US',
            qaRunner: $qaRunner,
            options: $options,
        );

        $result = $runner->process('/tmp/test.txt', 'fr-FR');
        $this->assertNotEmpty($result->qaIssues);
    }

    public function test_progress_callback_fires_with_correct_index_and_total(): void
    {
        $doc = $this->makeDoc(['First', 'Second', 'Third']);
        $registry = $this->makeRegistry($this->makeFilter($doc));

        $options = WorkflowOptions::defaults();
        $options->writeXliff = false;

        $runner = new WorkflowRunner(
            fileFilterRegistry: $registry,
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: 'en-US',
            options: $options,
        );

        $calls = [];
        $runner->onSegmentProcessed(function (SegmentPair $pair, int $index, int $total) use (&$calls): void {
            $calls[] = ['index' => $index, 'total' => $total];
        });

        $runner->process('/tmp/test.txt', 'fr-FR');

        $this->assertCount(3, $calls);
        $this->assertSame(0, $calls[0]['index']);
        $this->assertSame(3, $calls[0]['total']);
        $this->assertSame(2, $calls[2]['index']);
        $this->assertSame(3, $calls[2]['total']);
    }

    public function test_timings_keys_present_in_result(): void
    {
        $doc    = $this->makeDoc(['Hello']);
        $runner = $this->makeRunner($this->makeRegistry($this->makeFilter($doc)));

        $result = $runner->process('/tmp/test.txt', 'fr-FR');

        foreach (['extract', 'segment', 'tm', 'terminology', 'mt', 'qa', 'xliff'] as $key) {
            $this->assertArrayHasKey($key, $result->timings, "Missing timing key: {$key}");
        }
    }
}
