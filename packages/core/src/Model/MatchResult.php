<?php

declare(strict_types=1);

namespace CatFramework\Core\Model;

use CatFramework\Core\Enum\MatchType;

readonly class MatchResult
{
    public function __construct(
        /** The matched TU from the TM. */
        public TranslationUnit $translationUnit,

        /**
         * Match score from 0.0 to 1.0.
         * 1.0 = exact match (identical source text and code structure).
         * 0.7+ = typically useful fuzzy match.
         */
        public float $score,

        /** Classification of the match. */
        public MatchType $type,

        /**
         * Identifier of the TM this match came from. Relevant when querying
         * multiple TMs (project TM + master TM). Null = single-TM mode.
         */
        public ?string $memoryId = null,
    ) {}
}
