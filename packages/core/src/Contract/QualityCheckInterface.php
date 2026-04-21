<?php

declare(strict_types=1);

namespace CatFramework\Core\Contract;

use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\SegmentPair;

interface QualityCheckInterface
{
    /**
     * Run this check on one segment pair.
     *
     * @param SegmentPair $pair The pair to check.
     * @param string $sourceLanguage BCP 47 code (some checks are language-sensitive).
     * @param string $targetLanguage BCP 47 code.
     * @return QualityIssue[] Zero or more issues found. Empty = passed.
     */
    public function check(
        SegmentPair $pair,
        string $sourceLanguage,
        string $targetLanguage,
    ): array;

    /**
     * Unique identifier for this check. Used in QualityIssue::$checkId
     * and for enabling/disabling checks in configuration.
     * e.g., "tag_consistency", "number_mismatch", "double_space".
     */
    public function getId(): string;

    /**
     * Human-readable name for display in the UI.
     * e.g., "Tag Consistency", "Number Format Check".
     */
    public function getName(): string;
}
