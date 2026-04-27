<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

use CatFramework\Core\Contract\MachineTranslationInterface;
use CatFramework\Core\Contract\TerminologyProviderInterface;
use CatFramework\Core\Contract\TranslationMemoryInterface;
use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Enum\SegmentStatus;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Core\Model\TranslationUnit;
use CatFramework\Project\Store\SegmentStoreInterface;
use CatFramework\Project\Store\SkeletonStoreInterface;
use CatFramework\Qa\QualityRunner;
use CatFramework\Segmentation\SrxSegmentationEngine;
use CatFramework\Workflow\Exception\WorkflowException;
use CatFramework\Xliff\XliffWriter;

final class WorkflowRunner implements WorkflowRunnerInterface
{
    /** @var callable|null */
    private $onSegmentProcessed = null;

    public function __construct(
        private readonly FileFilterRegistry $fileFilterRegistry,
        private readonly SrxSegmentationEngine $segmentationEngine,
        private readonly XliffWriter $xliffWriter,
        private readonly string $sourceLang,
        private readonly ?TranslationMemoryInterface $translationMemory = null,
        private readonly ?TerminologyProviderInterface $terminologyProvider = null,
        private readonly ?MachineTranslationInterface $mtAdapter = null,
        private readonly ?QualityRunner $qaRunner = null,
        private readonly WorkflowOptions $options = new WorkflowOptions(),
        private readonly ?SegmentStoreInterface $segmentStore = null,
        private readonly ?SkeletonStoreInterface $skeletonStore = null,
    ) {}

    public function onSegmentProcessed(callable $cb): void
    {
        $this->onSegmentProcessed = $cb;
    }

    public function process(string $filePath, string $targetLang): WorkflowResult
    {
        $timings  = [];
        $fileId   = $this->segmentStore !== null || $this->skeletonStore !== null
            ? $this->generateFileId()
            : null;

        // Step 1: Extract document via filter
        $t = microtime(true);
        $filter = $this->fileFilterRegistry->getFilter($filePath);
        $doc = $filter->extract($filePath, $this->sourceLang, $targetLang);
        $timings['extract'] = microtime(true) - $t;

        // Step 2: Segmentation pass — expand multi-sentence structural units.
        // BilingualDocument::$segmentPairs is private, so we must rebuild the document
        // after expansion rather than mutating in place.
        $t = microtime(true);
        $expandedPairs = [];
        foreach ($doc->getSegmentPairs() as $pair) {
            $sentences = $this->segmentationEngine->segment($pair->source, $this->sourceLang);
            if (count($sentences) <= 1) {
                $expandedPairs[] = $pair;
            } else {
                foreach ($sentences as $sentence) {
                    $expandedPairs[] = new SegmentPair(
                        source: $sentence,
                        context: $pair->context,
                    );
                }
            }
        }
        $doc = new BilingualDocument(
            sourceLanguage: $doc->sourceLanguage,
            targetLanguage: $doc->targetLanguage,
            originalFile: $doc->originalFile,
            mimeType: $doc->mimeType,
            segmentPairs: $expandedPairs,
            skeleton: $doc->skeleton,
        );
        $timings['segment'] = microtime(true) - $t;

        // Steps 3a–e: Per-segment processing
        $exact = $fuzzy = $mt = $unmatched = 0;
        $total = count($expandedPairs);
        $tmTime = $terminologyTime = $mtTime = 0.0;

        foreach ($expandedPairs as $index => $pair) {
            $tmBestScore = 0.0;
            $tmMatched   = false;

            // TM lookup
            if ($this->translationMemory !== null) {
                $t = microtime(true);
                $matches = $this->translationMemory->lookup($pair->source, $this->sourceLang, $targetLang);
                $tmTime += microtime(true) - $t;

                if ($matches !== []) {
                    $best        = $matches[0];
                    $tmBestScore = $best->score;
                    $tmMatched   = true;

                    $pair->target = $best->translationUnit->target;

                    if ($best->score >= $this->options->autoConfirmThreshold) {
                        $pair->status   = SegmentStatus::Translated;
                        $pair->isLocked = true;
                        $exact++;
                    } else {
                        $pair->status = SegmentStatus::Draft;
                        $fuzzy++;
                    }
                }
            }

            // Terminology — run for timing; SegmentPair::context is readonly so results cannot be stored
            if ($this->terminologyProvider !== null) {
                $t = microtime(true);
                $this->terminologyProvider->recognize(
                    $pair->source->getPlainText(),
                    $this->sourceLang,
                    $targetLang,
                );
                $terminologyTime += microtime(true) - $t;
            }

            // MT fill: only when TM did not match AND best TM score is below the threshold.
            // MT fills segments whose best TM score falls below the threshold.
            // Default threshold 0.0 means MT never runs unless explicitly configured.
            if (!$tmMatched && $tmBestScore < $this->options->mtFillThreshold && $this->mtAdapter !== null) {
                $t = microtime(true);
                $pair->target = $this->mtAdapter->translate($pair->source, $this->sourceLang, $targetLang);
                $pair->status = SegmentStatus::Draft;
                $mtTime      += microtime(true) - $t;
                $mt++;
            } elseif (!$tmMatched) {
                $unmatched++;
            }

            // Stream segment to store if configured (before progress callback so the
            // caller's callback fires after the segment is already persisted)
            if ($this->segmentStore !== null && $fileId !== null) {
                $this->segmentStore->persistSegment($pair, $index, $fileId);
            }

            // Auto-populate TM with all pairs that have a translation
            if ($this->options->autoWriteToTm && $this->translationMemory !== null && $pair->target !== null) {
                $this->translationMemory->store(new TranslationUnit(
                    source:         $pair->source,
                    target:         $pair->target,
                    sourceLanguage: $this->sourceLang,
                    targetLanguage: $targetLang,
                    createdAt:      new \DateTimeImmutable(),
                ));
            }

            // Progress callback (0-based index)
            if ($this->onSegmentProcessed !== null) {
                ($this->onSegmentProcessed)($pair, $index, $total);
            }
        }

        $timings['tm']          = $tmTime;
        $timings['terminology'] = $terminologyTime;
        $timings['mt']          = $mtTime;

        // Step 4: QA
        $t = microtime(true);
        $qaIssues = [];
        if ($this->qaRunner !== null) {
            $qaIssues = $this->qaRunner->run($doc);
            $this->enforceQaThreshold($qaIssues);
        }
        $timings['qa'] = microtime(true) - $t;

        // Step 5: XLIFF output
        $t = microtime(true);
        $xliffPath = null;
        if ($this->options->writeXliff) {
            $dir       = $this->options->outputDir !== '' ? $this->options->outputDir : dirname($filePath);
            $xliffPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . basename($filePath) . '.xlf';
            $this->xliffWriter->write($doc, $xliffPath);
        }
        $timings['xliff'] = microtime(true) - $t;

        // Step 6: Persist skeleton
        $t = microtime(true);
        if ($this->skeletonStore !== null && $fileId !== null) {
            $skeletonBytes = json_encode($doc->skeleton, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->skeletonStore->store($fileId, $doc->mimeType, $skeletonBytes);
        }
        $timings['store'] = microtime(true) - $t;

        return new WorkflowResult(
            document: $doc,
            qaIssues: $qaIssues,
            matchStats: new TmMatchStats(
                exact: $exact,
                fuzzy: $fuzzy,
                mt: $mt,
                unmatched: $unmatched,
            ),
            xliffPath: $xliffPath,
            timings: $timings,
            storeFileId: $fileId,
        );
    }

    /** @param QualityIssue[] $issues */
    private function enforceQaThreshold(array $issues): void
    {
        if ($this->options->qaFailOnSeverity === null || $issues === []) {
            return;
        }

        $threshold      = QualitySeverity::from($this->options->qaFailOnSeverity);
        $thresholdLevel = $this->severityLevel($threshold);

        foreach ($issues as $issue) {
            if ($this->severityLevel($issue->severity) >= $thresholdLevel) {
                throw new WorkflowException(
                    "QA check '{$issue->checkId}' failed with severity {$issue->severity->value}: {$issue->message}"
                );
            }
        }
    }

    private function severityLevel(QualitySeverity $severity): int
    {
        return match ($severity) {
            QualitySeverity::INFO    => 0,
            QualitySeverity::WARNING => 1,
            QualitySeverity::ERROR   => 2,
        };
    }

    private function generateFileId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
