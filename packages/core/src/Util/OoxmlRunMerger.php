<?php

declare(strict_types=1);

namespace CatFramework\Core\Util;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;

/**
 * Shared run-merging utilities for OOXML filters (DOCX, XLSX, PPTX).
 *
 * All methods are stateless and work on plain MergedRun arrays and Segment
 * elements. No DOM operations — filters handle DOM traversal themselves,
 * passing the extracted rPrXml strings and text here for processing.
 */
final class OoxmlRunMerger
{
    /**
     * Collapse adjacent MergedRuns that share the same rPrXml into one run.
     * Eliminates Word/Excel run-fragmentation artefacts before InlineCode generation.
     *
     * @param  MergedRun[] $rawRuns Non-empty.
     * @return MergedRun[]
     */
    public static function merge(array $rawRuns): array
    {
        $merged  = [];
        $current = $rawRuns[0];

        for ($i = 1; $i < count($rawRuns); $i++) {
            if ($rawRuns[$i]->rPrXml === $current->rPrXml) {
                $current = new MergedRun($current->rPrXml, $current->text . $rawRuns[$i]->text);
            } else {
                $merged[] = $current;
                $current  = $rawRuns[$i];
            }
        }
        $merged[] = $current;

        return $merged;
    }

    /**
     * Returns the rPrXml string used by the greatest total character count.
     * This becomes the "base" formatting — text in base formatting is stored
     * as plain strings in the Segment; other formatting becomes InlineCode pairs.
     *
     * @param MergedRun[] $mergedRuns Non-empty.
     */
    public static function findBaseRpr(array $mergedRuns): string
    {
        $lengths = [];
        foreach ($mergedRuns as $run) {
            $lengths[$run->rPrXml] = ($lengths[$run->rPrXml] ?? 0) + mb_strlen($run->text);
        }
        arsort($lengths);

        return (string) array_key_first($lengths);
    }

    /**
     * Convert merged runs into the mixed string / InlineCode array that becomes
     * a source Segment's content.
     *
     * Runs whose rPrXml matches $baseRpr emit plain strings. All other runs are
     * wrapped in OPENING / CLOSING InlineCode pairs whose data stores the rPrXml
     * for later reconstruction by the filter's rebuild() method.
     *
     * @param  MergedRun[] $mergedRuns
     * @return array<string|InlineCode>
     */
    public static function buildSegmentElements(array $mergedRuns, string $baseRpr): array
    {
        $elements = [];
        $codeId   = 1;

        foreach ($mergedRuns as $run) {
            if ($run->rPrXml === $baseRpr) {
                $elements[] = $run->text;
            } else {
                $id         = (string) $codeId++;
                $elements[] = new InlineCode(id: $id, type: InlineCodeType::OPENING, data: $run->rPrXml, displayText: '{' . $id . '}');
                $elements[] = $run->text;
                $elements[] = new InlineCode(id: $id, type: InlineCodeType::CLOSING, data: $run->rPrXml, displayText: '{/' . $id . '}');
            }
        }

        return $elements;
    }

    /**
     * Reverse of buildSegmentElements: convert a translated Segment back into
     * an ordered list of MergedRuns for the filter's rebuild() to serialize.
     *
     * Walk Segment elements:
     *  - Plain string: accumulate under current formatting.
     *  - InlineCode OPENING: flush buffer, switch current formatting to code data.
     *  - InlineCode CLOSING: flush buffer, reset formatting to baseRpr.
     *  - InlineCode STANDALONE: flush buffer, emit a run with no text (caller skips).
     *
     * @return MergedRun[]
     */
    public static function segmentToRuns(Segment $segment, string $baseRpr): array
    {
        $currentRpr = $baseRpr;
        $buffer     = '';
        $runs       = [];

        foreach ($segment->getElements() as $element) {
            if (is_string($element)) {
                $buffer .= $element;
                continue;
            }

            if ($buffer !== '') {
                $runs[] = new MergedRun($currentRpr, $buffer);
                $buffer = '';
            }

            match ($element->type) {
                InlineCodeType::OPENING    => $currentRpr = $element->data,
                InlineCodeType::CLOSING    => $currentRpr = $baseRpr,
                InlineCodeType::STANDALONE => null,
            };
        }

        if ($buffer !== '') {
            $runs[] = new MergedRun($currentRpr, $buffer);
        }

        return $runs;
    }
}
