<?php

declare(strict_types=1);

namespace CatFramework\Segmentation;

use CatFramework\Core\Contract\SegmentationEngineInterface;
use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Exception\SegmentationException;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Srx\LanguageRule;
use CatFramework\Srx\SegmentationRuleSet;
use CatFramework\Srx\SrxParser;

class SrxSegmentationEngine implements SegmentationEngineInterface
{
    private ?SegmentationRuleSet $ruleSet = null;

    public function loadRules(string $srxFilePath): void
    {
        $this->ruleSet = (new SrxParser())->parse($srxFilePath);
    }

    public function segment(Segment $input, string $languageCode): array
    {
        if ($this->ruleSet === null) {
            $this->loadRules(SrxParser::defaultSrxPath());
        }

        $text = $input->getPlainText();
        if (trim($text) === '') {
            return [$input];
        }

        $languageRule = $this->ruleSet->rulesFor($languageCode);
        $breaks       = $this->findBreakPositions($text, $languageRule);

        if (empty($breaks)) {
            return [$input];
        }

        return $this->splitIntoSegments($input, $breaks);
    }

    /**
     * Scans the plain text for sentence boundary positions.
     *
     * For each character position, applies SRX rules in order (first match wins):
     *   - no-break rule match → skip this position
     *   - break rule match    → record as a boundary
     *
     * Returns an array of character offsets (mb_strlen units) where breaks occur.
     *
     * @return int[]
     */
    private function findBreakPositions(string $text, LanguageRule $languageRule): array
    {
        if (empty($languageRule->rules)) {
            return [];
        }

        $len    = mb_strlen($text, 'UTF-8');
        $breaks = [];

        for ($i = 1; $i < $len; $i++) {
            $before = mb_substr($text, 0, $i, 'UTF-8');
            $after  = mb_substr($text, $i, null, 'UTF-8');

            foreach ($languageRule->rules as $rule) {
                $matchesBefore = $rule->before === ''
                    || (bool) @preg_match('/' . $rule->before . '$/u', $before);
                $matchesAfter  = $rule->after  === ''
                    || (bool) @preg_match('/^(?:' . $rule->after  . ')/u', $after);

                if ($matchesBefore && $matchesAfter) {
                    if ($rule->break) {
                        $breaks[] = $i;
                    }
                    break; // first matching rule wins
                }
            }
        }

        // Advance each break past inter-sentence whitespace so it stays in the preceding segment.
        // E.g. break at 12 in "Hello world. Next" → advance to 13 so segment A = "Hello world. ".
        $adjusted = [];
        foreach ($breaks as $pos) {
            while ($pos < $len && preg_match('/^\s/u', mb_substr($text, $pos, 1, 'UTF-8'))) {
                $pos++;
            }
            if (!in_array($pos, $adjusted, true)) {
                $adjusted[] = $pos;
            }
        }

        return $adjusted;
    }

    /**
     * Splits $input's elements at the given character break positions.
     *
     * Steps:
     *   1. Map each element to its character-offset range in the plain text.
     *   2. Distribute elements into per-segment buckets.
     *   3. Fix spanning InlineCodes: mark orphaned codes as isolated,
     *      append synthetic closing codes at segment ends, prepend
     *      synthetic opening codes at segment starts.
     *
     * @param  int[]    $breaks Character offsets where sentence boundaries occur.
     * @return Segment[]
     */
    private function splitIntoSegments(Segment $input, array $breaks): array
    {
        $elements   = $input->getElements();
        $boundaries = [0, ...$breaks, mb_strlen($input->getPlainText(), 'UTF-8')];
        $bucketCount = count($boundaries) - 1;

        // Step 1 – map element index → [start, end] in plain text coordinates
        $posMap = $this->buildPositionMap($elements);

        // Step 2 – distribute elements into buckets
        $buckets = array_fill(0, $bucketCount, []);

        foreach ($elements as $idx => $el) {
            ['start' => $elStart, 'end' => $elEnd] = $posMap[$idx];

            if (is_string($el)) {
                for ($s = 0; $s < $bucketCount; $s++) {
                    $segStart    = $boundaries[$s];
                    $segEnd      = $boundaries[$s + 1];
                    $overlapStart = max($elStart, $segStart);
                    $overlapEnd   = min($elEnd, $segEnd);

                    if ($overlapStart < $overlapEnd) {
                        $sub = mb_substr($el, $overlapStart - $elStart, $overlapEnd - $overlapStart, 'UTF-8');
                        if ($sub !== '') {
                            $buckets[$s][] = $sub;
                        }
                    }
                }
            } else {
                // InlineCode is zero-width; assign to the segment whose range contains $elStart
                $target = $bucketCount - 1;
                for ($s = 0; $s < $bucketCount - 1; $s++) {
                    if ($elStart < $boundaries[$s + 1]) {
                        $target = $s;
                        break;
                    }
                }
                $buckets[$target][] = $el;
            }
        }

        // Step 3 – fix spanning InlineCodes across bucket boundaries
        $buckets = $this->fixSpanningCodes($buckets);

        // Build Segment objects
        $segments = [];
        foreach ($buckets as $s => $bucket) {
            $segId      = $input->id . ':' . ($s + 1);
            $segments[] = new Segment($segId, $bucket);
        }

        return $segments;
    }

    /**
     * Builds a position map: element index → [start, end] in plain text characters.
     * InlineCodes are zero-width (start === end).
     *
     * @param  array<string|InlineCode> $elements
     * @return array<int, array{start:int,end:int}>
     */
    private function buildPositionMap(array $elements): array
    {
        $map = [];
        $pos = 0;

        foreach ($elements as $i => $el) {
            if (is_string($el)) {
                $len    = mb_strlen($el, 'UTF-8');
                $map[$i] = ['start' => $pos, 'end' => $pos + $len];
                $pos    += $len;
            } else {
                $map[$i] = ['start' => $pos, 'end' => $pos];
            }
        }

        return $map;
    }

    /**
     * Repairs InlineCodes that span segment boundaries.
     *
     * For each bucket in order:
     *   - Prepend synthetic OPENING codes (isolated) for any codes still open
     *     from a prior bucket.
     *   - Track OPENING and CLOSING codes within the bucket.
     *   - A CLOSING that has no matching OPENING in this bucket comes from a
     *     prior bucket's span → mark it isolated.
     *   - OPENINGs that are still unmatched at end of bucket span into the
     *     next bucket → mark them isolated, append a synthetic CLOSING, and
     *     carry them forward.
     *
     * @param  array<int, array<string|InlineCode>> $buckets
     * @return array<int, array<string|InlineCode>>
     */
    private function fixSpanningCodes(array $buckets): array
    {
        // $pending: InlineCode[] of OPENING codes opened in a prior bucket, not yet closed.
        // Stored in nesting order (outermost first).
        $pending = [];

        foreach ($buckets as $s => $bucket) {
            // Prepend synthetic re-opens for codes still pending from prior buckets
            $prepend = array_map(
                fn(InlineCode $c) => new InlineCode($c->id, InlineCodeType::OPENING, $c->data, $c->displayText, true),
                $pending,
            );

            // Scan bucket: track locally opened codes and fix orphaned closings
            $openedHere = []; // id => InlineCode (in insertion order)
            $fixed      = [];

            foreach ($bucket as $el) {
                if (!($el instanceof InlineCode)) {
                    $fixed[] = $el;
                    continue;
                }

                if ($el->type === InlineCodeType::OPENING) {
                    $openedHere[$el->id] = $el;
                    $fixed[]             = $el;
                } elseif ($el->type === InlineCodeType::CLOSING) {
                    if (isset($openedHere[$el->id])) {
                        unset($openedHere[$el->id]);
                        $fixed[] = $el; // matched within this bucket: not isolated
                    } else {
                        // Orphaned closing: its opening was in a prior bucket
                        $fixed[] = new InlineCode($el->id, InlineCodeType::CLOSING, $el->data, $el->displayText, true);
                        // Remove from pending (it's now closed)
                        $pending = array_values(array_filter($pending, fn($c) => $c->id !== $el->id));
                    }
                } else {
                    $fixed[] = $el; // STANDALONE
                }
            }

            // Codes still open at end of bucket span into the next bucket
            $spanning = array_values($openedHere);

            // Mark the spanning OPENINGs as isolated in $fixed
            if (!empty($spanning)) {
                $spanIds = array_flip(array_map(fn($c) => $c->id, $spanning));
                foreach ($fixed as $i => $el) {
                    if ($el instanceof InlineCode && isset($spanIds[$el->id]) && $el->type === InlineCodeType::OPENING) {
                        $fixed[$i] = new InlineCode($el->id, $el->type, $el->data, $el->displayText, true);
                    }
                }
            }

            // Append synthetic CLOSINGs in reverse nesting order (innermost first)
            $append = [];
            foreach (array_reverse($spanning) as $spanCode) {
                $tagName  = $this->extractTagName($spanCode->data);
                $closeTag = "</{$tagName}>";
                $append[] = new InlineCode($spanCode->id, InlineCodeType::CLOSING, $closeTag, $closeTag, true);
            }

            $buckets[$s] = [...$prepend, ...$fixed, ...$append];

            // Carry spanning codes forward (add to pending in original nesting order)
            foreach ($spanning as $spanCode) {
                $pending[] = $spanCode;
            }
        }

        return $buckets;
    }

    /** Extracts the tag name from a serialized opening tag like "<a href='...'>". */
    private function extractTagName(string $openTag): string
    {
        if (preg_match('/<(\w+)/u', $openTag, $m)) {
            return strtolower($m[1]);
        }
        return 'span'; // safe fallback
    }
}
