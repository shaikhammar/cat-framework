<?php

declare(strict_types=1);

namespace CatFramework\Qa\Check;

use CatFramework\Core\Contract\QualityCheckInterface;
use CatFramework\Core\Contract\TerminologyProviderInterface;
use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\SegmentPair;

final class TerminologyConsistencyCheck implements QualityCheckInterface
{
    public function __construct(
        private readonly ?TerminologyProviderInterface $provider = null,
    ) {}

    public function getId(): string
    {
        return 'terminology_violation';
    }

    public function getName(): string
    {
        return 'Terminology Consistency';
    }

    public function check(SegmentPair $pair, string $sourceLanguage, string $targetLanguage): array
    {
        if ($this->provider === null || $pair->target === null || $pair->target->isEmpty()) {
            return [];
        }

        $sourcePlain = $pair->source->getPlainText();
        $targetPlain = $pair->target->getPlainText();

        $matches = $this->provider->recognize($sourcePlain, $sourceLanguage, $targetLanguage);

        $issues = [];

        foreach ($matches as $match) {
            $entry = $match->entry;

            if ($entry->forbidden) {
                // A forbidden source term was recognised — check if the forbidden
                // target equivalent appears in the translation.
                if (mb_stripos($targetPlain, $entry->targetTerm) !== false) {
                    $issues[] = new QualityIssue(
                        checkId: $this->getId(),
                        severity: QualitySeverity::WARNING,
                        message: "Forbidden term \"{$entry->targetTerm}\" used in translation.",
                        segmentId: $pair->source->id,
                    );
                }
            } else {
                // Approved term: check it appears in the target
                if (mb_stripos($targetPlain, $entry->targetTerm) === false) {
                    $issues[] = new QualityIssue(
                        checkId: $this->getId(),
                        severity: QualitySeverity::INFO,
                        message: "Approved term \"{$entry->targetTerm}\" (for \"{$entry->sourceTerm}\") not found in translation.",
                        segmentId: $pair->source->id,
                    );
                }
            }
        }

        return $issues;
    }
}
