<?php

declare(strict_types=1);

namespace CatFramework\Core\Contract;

use CatFramework\Core\Exception\SegmentationException;
use CatFramework\Core\Model\Segment;

interface SegmentationEngineInterface
{
    /**
     * Split a paragraph-level Segment into sentence-level Segments.
     *
     * The input is a structural unit from the file filter (one paragraph,
     * one table cell, one text node). Output is one or more sentence-level
     * Segments. If the input is already a single sentence, returns [$input].
     *
     * InlineCodes in the input are distributed to the correct output
     * Segments based on their position. Codes that span a split boundary
     * are handled by closing at end of segment N and re-opening at start
     * of segment N+1 (isIsolated = true on both synthetic codes).
     *
     * @param Segment $input A paragraph-level Segment (may contain InlineCodes).
     * @param string $languageCode BCP 47 code. Determines which SRX rules apply.
     * @return Segment[] Sentence-level segments. Never empty.
     */
    public function segment(Segment $input, string $languageCode): array;

    /**
     * Load segmentation rules from an SRX file.
     *
     * Replaces any previously loaded rules for the languages defined in
     * the SRX file. Multiple loadRules() calls for different languages
     * are additive.
     *
     * @param string $srxFilePath Path to an SRX 2.0 file.
     * @throws SegmentationException On invalid SRX.
     */
    public function loadRules(string $srxFilePath): void;
}
