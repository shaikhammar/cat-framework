<?php

declare(strict_types=1);

namespace CatFramework\Qa\Check;

use CatFramework\Core\Contract\QualityCheckInterface;
use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\SegmentPair;

final class DoubleSpaceCheck implements QualityCheckInterface
{
    public function getId(): string
    {
        return 'double_space';
    }

    public function getName(): string
    {
        return 'Double Space';
    }

    public function check(SegmentPair $pair, string $sourceLanguage, string $targetLanguage): array
    {
        if ($pair->target === null || $pair->target->isEmpty()) {
            return [];
        }

        $text = $pair->target->getPlainText();

        // Find the first occurrence of two or more consecutive spaces
        if (preg_match('/  +/', $text, $matches, PREG_OFFSET_CAPTURE)) {
            $byteOffset = $matches[0][1];
            // Convert byte offset to character offset for multibyte safety
            $charOffset = mb_strlen(substr($text, 0, $byteOffset));

            return [new QualityIssue(
                checkId: $this->getId(),
                severity: QualitySeverity::INFO,
                message: 'Target contains consecutive spaces.',
                segmentId: $pair->source->id,
                offset: $charOffset,
                length: mb_strlen($matches[0][0]),
            )];
        }

        return [];
    }
}
