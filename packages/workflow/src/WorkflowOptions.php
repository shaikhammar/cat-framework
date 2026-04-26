<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

final class WorkflowOptions
{
    /**
     * MT fills a segment if the best TM score is strictly less than this value.
     * 0.0 = MT never fills (default — opt-in).
     * 1.0 = MT fills every segment that has no exact TM match.
     * 0.75 = MT fills when best TM score is below 75%.
     */
    public float $mtFillThreshold = 0.0;

    /**
     * Auto-confirm and lock a segment if TM score is >= this value.
     * 1.0 = only exact matches are auto-confirmed (default).
     */
    public float $autoConfirmThreshold = 1.0;

    /**
     * Directory for XLIFF output. Empty string = same directory as source file.
     */
    public string $outputDir = '';

    /** Write XLIFF file. false = dry-run mode (no files written). */
    public bool $writeXliff = true;

    /**
     * Throw WorkflowException if any QA issue has severity >= this value.
     * null = never throw. Values: 'error' | 'warning' | 'info'.
     */
    public ?string $qaFailOnSeverity = null;

    /**
     * Automatically store each translated segment pair back into the TM after processing.
     * Useful for bulk-importing existing bilingual files or propagating MT output into TM.
     * Only pairs that have a target segment are stored.
     */
    public bool $autoWriteToTm = false;

    public static function defaults(): self
    {
        return new self();
    }
}
