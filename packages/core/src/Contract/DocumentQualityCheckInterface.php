<?php

declare(strict_types=1);

namespace CatFramework\Core\Contract;

use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\QualityIssue;

interface DocumentQualityCheckInterface
{
    /**
     * Run this check across the full document.
     *
     * Called once per document after all segment pairs are available.
     * Use when the check requires cross-segment context, such as detecting
     * inconsistent translations of the same source text.
     *
     * @return QualityIssue[]
     */
    public function checkDocument(
        BilingualDocument $document,
        string $sourceLanguage,
        string $targetLanguage,
    ): array;

    /** Unique identifier for this check, e.g. "segment_consistency". */
    public function getId(): string;

    /** Human-readable name for display in the UI. */
    public function getName(): string;
}
