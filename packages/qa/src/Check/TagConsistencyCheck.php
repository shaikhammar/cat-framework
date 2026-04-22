<?php

declare(strict_types=1);

namespace CatFramework\Qa\Check;

use CatFramework\Core\Contract\QualityCheckInterface;
use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\SegmentPair;

final class TagConsistencyCheck implements QualityCheckInterface
{
    public function getId(): string
    {
        return 'tag_consistency';
    }

    public function getName(): string
    {
        return 'Tag Consistency';
    }

    public function check(SegmentPair $pair, string $sourceLanguage, string $targetLanguage): array
    {
        if ($pair->target === null) {
            return [];
        }

        $sourceCodes = $pair->source->getInlineCodes();
        $targetCodes = $pair->target->getInlineCodes();

        $sourceIds = $this->indexById($sourceCodes);
        $targetIds = $this->indexById($targetCodes);

        $issues = [];

        foreach ($sourceIds as $id => $types) {
            if (!isset($targetIds[$id])) {
                $label = $this->label($sourceCodes, $id);
                $issues[] = new QualityIssue(
                    checkId: $this->getId(),
                    severity: QualitySeverity::ERROR,
                    message: "Tag {$label} from source is missing in target.",
                    segmentId: $pair->source->id,
                );
            }
        }

        foreach ($targetIds as $id => $types) {
            if (!isset($sourceIds[$id])) {
                $label = $this->label($targetCodes, $id);
                $issues[] = new QualityIssue(
                    checkId: $this->getId(),
                    severity: QualitySeverity::ERROR,
                    message: "Tag {$label} in target has no match in source.",
                    segmentId: $pair->source->id,
                );
            }
        }

        return $issues;
    }

    /** @param InlineCode[] $codes */
    private function indexById(array $codes): array
    {
        $index = [];
        foreach ($codes as $code) {
            $index[$code->id][] = $code->type;
        }
        return $index;
    }

    /** @param InlineCode[] $codes */
    private function label(array $codes, string|int $id): string
    {
        foreach ($codes as $code) {
            if ($code->id === (string) $id && $code->displayText !== null) {
                return $code->displayText;
            }
        }
        return '{' . $id . '}';
    }
}
