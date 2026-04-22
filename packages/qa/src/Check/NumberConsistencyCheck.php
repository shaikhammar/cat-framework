<?php

declare(strict_types=1);

namespace CatFramework\Qa\Check;

use CatFramework\Core\Contract\QualityCheckInterface;
use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\SegmentPair;
use NumberFormatter;

final class NumberConsistencyCheck implements QualityCheckInterface
{
    public function getId(): string
    {
        return 'number_mismatch';
    }

    public function getName(): string
    {
        return 'Number Consistency';
    }

    public function check(SegmentPair $pair, string $sourceLanguage, string $targetLanguage): array
    {
        if ($pair->target === null || $pair->target->isEmpty()) {
            return [];
        }

        $sourceNumbers = $this->extractNumbers($pair->source->getPlainText(), $sourceLanguage);
        $targetNumbers = $this->extractNumbers($pair->target->getPlainText(), $targetLanguage);

        $issues = [];

        foreach ($sourceNumbers as $number) {
            if (!in_array($number, $targetNumbers, strict: true)) {
                $issues[] = new QualityIssue(
                    checkId: $this->getId(),
                    severity: QualitySeverity::WARNING,
                    message: "Number \"{$number}\" from source not found in target (may be intentionally localized).",
                    segmentId: $pair->source->id,
                );
            }
        }

        return $issues;
    }

    /** @return float[] */
    private function extractNumbers(string $text, string $locale): array
    {
        // Match numeric tokens: digits with optional sign, decimal and thousands separators.
        // The regex captures the raw token; we then parse it locale-aware.
        preg_match_all('/[+\-]?\d[\d.,\s]*/', $text, $matches);

        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $numbers = [];

        foreach ($matches[0] as $token) {
            $token = trim($token);
            $parsed = $formatter->parse($token);
            if ($parsed !== false) {
                $numbers[] = $parsed;
            } else {
                // Fallback: strip all non-digit/dot chars and cast
                $clean = preg_replace('/[^\d.]/', '', $token);
                if ($clean !== '' && is_numeric($clean)) {
                    $numbers[] = (float) $clean;
                }
            }
        }

        return $numbers;
    }
}
