<?php

declare(strict_types=1);

namespace CatFramework\Qa\Check;

use CatFramework\Core\Contract\DocumentQualityCheckInterface;
use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\QualityIssue;

final class SegmentConsistencyCheck implements DocumentQualityCheckInterface
{
    public function getId(): string
    {
        return 'segment_consistency';
    }

    public function getName(): string
    {
        return 'Segment Consistency';
    }

    public function checkDocument(
        BilingualDocument $document,
        string $sourceLanguage,
        string $targetLanguage,
    ): array {
        // Pass 1: group pairs by normalised source → [['pair', 'target'], ...]
        $groups = [];
        foreach ($document->getSegmentPairs() as $pair) {
            if ($pair->target === null || $pair->target->isEmpty()) {
                continue;
            }
            $key = trim($pair->source->getPlainText());
            $groups[$key][] = ['pair' => $pair, 'target' => $pair->target->getPlainText()];
        }

        // Pass 2: flag every group that has 2+ distinct target texts
        $issues = [];
        foreach ($groups as $sourceText => $entries) {
            $distinctTargets = array_unique(array_column($entries, 'target'));
            if (count($distinctTargets) < 2) {
                continue;
            }

            $listed = implode(' | ', array_map(fn($t) => "\"{$t}\"", $distinctTargets));
            foreach ($entries as ['pair' => $pair, 'target' => $target]) {
                $issues[] = new QualityIssue(
                    checkId: $this->getId(),
                    severity: QualitySeverity::WARNING,
                    message: "Source \"{$sourceText}\" has inconsistent translations: {$listed}. This segment: \"{$target}\".",
                    segmentId: $pair->source->id,
                );
            }
        }

        return $issues;
    }
}
