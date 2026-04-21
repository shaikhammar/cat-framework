<?php

declare(strict_types=1);

namespace CatFramework\Core\Model;

use CatFramework\Core\Enum\QualitySeverity;

readonly class QualityIssue
{
    public function __construct(
        /**
         * Identifier of the check that found this issue.
         * e.g., "tag_consistency", "number_mismatch", "empty_translation".
         * Used for filtering and suppression.
         */
        public string $checkId,

        /** Severity. ERROR = must fix, WARNING = should review, INFO = suggestion. */
        public QualitySeverity $severity,

        /**
         * Human-readable description. Should be specific enough to act on.
         * Bad: "Tag error." Good: "Opening tag {1} in source has no match in target."
         */
        public string $message,

        /** Source segment ID of the pair this issue relates to. */
        public string $segmentId,

        /**
         * Character offset in the TARGET segment's plain text where the issue
         * starts. Null if the issue is about the whole segment (e.g., empty
         * translation). Used for highlighting in the editor.
         */
        public ?int $offset = null,

        /**
         * Length of the problematic span in the target text.
         * Null = point issue (no span) or whole-segment issue.
         */
        public ?int $length = null,
    ) {}
}
