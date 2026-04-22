<?php

declare(strict_types=1);

namespace CatFramework\Qa\Check;

use CatFramework\Core\Contract\QualityCheckInterface;
use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\SegmentPair;

final class EmptyTranslationCheck implements QualityCheckInterface
{
    public function getId(): string
    {
        return 'empty_translation';
    }

    public function getName(): string
    {
        return 'Empty Translation';
    }

    public function check(SegmentPair $pair, string $sourceLanguage, string $targetLanguage): array
    {
        if ($pair->source->isEmpty()) {
            return [];
        }

        if ($pair->target === null || $pair->target->isEmpty()) {
            return [new QualityIssue(
                checkId: $this->getId(),
                severity: QualitySeverity::ERROR,
                message: 'Segment has source text but no translation.',
                segmentId: $pair->source->id,
            )];
        }

        return [];
    }
}
