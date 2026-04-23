<?php

declare(strict_types=1);

namespace CatFramework\Core\Util;

/**
 * One run (or group of merged adjacent runs) from an OOXML element.
 *
 * Adjacent runs with identical serialized <rPr> XML are collapsed into a
 * single MergedRun before InlineCode generation. This eliminates Office's
 * habit of splitting logically uniform text across multiple runs.
 */
readonly class MergedRun
{
    public function __construct(
        /**
         * Serialized <rPr>…</rPr> XML, or '' when the run has no explicit
         * formatting properties. The string includes any namespace declarations
         * needed to re-parse it standalone (PHP DOMDocument adds these on saveXML).
         */
        public string $rPrXml,

        /** Concatenated plain text of all <t> children in the run group. */
        public string $text,
    ) {}
}
