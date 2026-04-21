<?php

declare(strict_types=1);

namespace CatFramework\Core\Model;

use CatFramework\Core\Enum\InlineCodeType;

readonly class InlineCode
{
    public function __construct(
        /**
         * Pairs opening and closing codes. Two InlineCode objects with the
         * same ID and types OPENING/CLOSING are two halves of one tag pair.
         * Standalone codes have a unique ID with no pair.
         */
        public string $id,

        /**
         * Whether this is an opening, closing, or standalone tag.
         */
        public InlineCodeType $type,

        /**
         * Original markup content. Opaque to everything except the filter
         * that created it. For DOCX: raw OOXML run properties. For HTML:
         * the literal tag string. Used to reconstruct the original file.
         */
        public string $data,

        /**
         * Human-readable label for the translation editor UI.
         * e.g., "<b>", "</b>", "{1}", "<br/>".
         * Null means the UI should generate a generic numbered placeholder.
         */
        public ?string $displayText = null,

        /**
         * True when this code was created by the segmenter splitting a tag
         * that spanned a sentence boundary. An isolated OPENING has no
         * matching CLOSING in the same Segment (and vice versa).
         *
         * Used during de-segmentation (merge) to detect and remove synthetic
         * codes before rebuilding the original file. Maps to XLIFF 1.2 <it>.
         *
         * See: planning/05-risks-and-hard-problems.md, Risk 1.
         */
        public bool $isIsolated = false,
    ) {}
}
