<?php

declare(strict_types=1);

namespace CatFramework\FilterDocx;

/**
 * One run (or group of merged adjacent runs) from a DOCX paragraph.
 *
 * Adjacent <w:r> elements with identical <w:rPr> XML are collapsed into a
 * single MergedRun before InlineCode generation. This eliminates Word's habit
 * of splitting text across runs for no visible reason.
 */
readonly class MergedRun
{
    public function __construct(
        /**
         * Serialized <w:rPr>…</w:rPr> XML, or '' when the run has no explicit
         * formatting properties. The string includes any namespace declarations
         * needed to re-parse it standalone (PHP DOMDocument adds these on saveXML).
         */
        public string $rPrXml,

        /** Concatenated plain text of all <w:t> children. */
        public string $text,
    ) {}
}
