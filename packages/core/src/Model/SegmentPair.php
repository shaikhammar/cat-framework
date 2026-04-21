<?php

declare(strict_types=1);

namespace CatFramework\Core\Model;

use CatFramework\Core\Enum\SegmentState;

class SegmentPair
{
    public function __construct(
        /**
         * Original text extracted by the file filter. Should not be modified
         * after creation (no enforcement, but modifying it breaks file rebuild).
         */
        public readonly Segment $source,

        /**
         * Translation. Null = untranslated. Created empty when the document
         * is first opened, populated as the translator works.
         */
        public ?Segment $target = null,

        /**
         * Workflow state. Tracks where this pair is in the translation process.
         */
        public SegmentState $state = SegmentState::INITIAL,

        /**
         * Locked pairs should not be edited. Used for: pre-translated segments
         * from TM (100% matches auto-locked), segments the PM marks as final,
         * or non-translatable content the filter decided to expose.
         */
        public bool $isLocked = false,

        /**
         * Filter-specific reconstruction data. The filter stores whatever it
         * needs to put this segment back into the correct location in the
         * original file. Opaque to everything outside the filter.
         *
         * @var array<string, mixed>
         */
        public readonly array $context = [],
    ) {}
}
