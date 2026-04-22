<?php

declare(strict_types=1);

namespace CatFramework\Qa\Check;

use CatFramework\Core\Contract\QualityCheckInterface;
use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\SegmentPair;

final class WhitespaceCheck implements QualityCheckInterface
{
    public function getId(): string
    {
        return 'whitespace_mismatch';
    }

    public function getName(): string
    {
        return 'Whitespace Mismatch';
    }

    public function check(SegmentPair $pair, string $sourceLanguage, string $targetLanguage): array
    {
        if ($pair->target === null || $pair->target->isEmpty()) {
            return [];
        }

        $sourceText = $pair->source->getPlainText();
        $targetText = $pair->target->getPlainText();

        $issues = [];

        $sourceLeading  = mb_strlen($sourceText) - mb_strlen(ltrim($sourceText));
        $targetLeading  = mb_strlen($targetText) - mb_strlen(ltrim($targetText));
        $sourceTrailing = mb_strlen($sourceText) - mb_strlen(rtrim($sourceText));
        $targetTrailing = mb_strlen($targetText) - mb_strlen(rtrim($targetText));

        if ($sourceLeading !== $targetLeading) {
            $issues[] = new QualityIssue(
                checkId: $this->getId(),
                severity: QualitySeverity::WARNING,
                message: "Leading whitespace mismatch: source has {$sourceLeading} space(s), target has {$targetLeading}.",
                segmentId: $pair->source->id,
                offset: 0,
            );
        }

        if ($sourceTrailing !== $targetTrailing) {
            $issues[] = new QualityIssue(
                checkId: $this->getId(),
                severity: QualitySeverity::WARNING,
                message: "Trailing whitespace mismatch: source has {$sourceTrailing} space(s), target has {$targetTrailing}.",
                segmentId: $pair->source->id,
            );
        }

        return $issues;
    }
}
