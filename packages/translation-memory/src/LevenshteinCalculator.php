<?php

declare(strict_types=1);

namespace CatFramework\TranslationMemory;

class LevenshteinCalculator
{
    /**
     * Edit distance between two strings at grapheme-cluster level.
     * Uses PHP's native levenshtein() for ASCII strings (fast path).
     */
    public function distance(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        // ASCII fast path — native C implementation is ~100x faster than PHP DP
        if (!preg_match('/[^\x00-\x7F]/', $a) && !preg_match('/[^\x00-\x7F]/', $b)) {
            return levenshtein($a, $b);
        }

        $aChars = $this->splitGraphemes($a);
        $bChars = $this->splitGraphemes($b);
        $m      = count($aChars);
        $n      = count($bChars);

        if ($m === 0) {
            return $n;
        }
        if ($n === 0) {
            return $m;
        }

        // Swap so the row is sized to the shorter string: O(min(m,n)) space
        if ($n > $m) {
            [$aChars, $bChars, $m, $n] = [$bChars, $aChars, $n, $m];
        }

        $row = range(0, $n); // dp[0][0..n]

        for ($i = 1; $i <= $m; $i++) {
            $prev   = $i - 1; // dp[i-1][0]
            $row[0] = $i;     // dp[i][0]

            for ($j = 1; $j <= $n; $j++) {
                $diag    = $prev;
                $prev    = $row[$j]; // save dp[i-1][j] before overwriting
                $cost    = $aChars[$i - 1] === $bChars[$j - 1] ? 0 : 1;
                $row[$j] = min($prev + 1, $row[$j - 1] + 1, $diag + $cost);
            }
        }

        return $row[$n];
    }

    /**
     * Similarity score from 0.0 to 1.0.
     * Formula: 1.0 - (distance / max(len_a, len_b))
     */
    public function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $maxLen = max($this->graphemeLength($a), $this->graphemeLength($b));

        if ($maxLen === 0) {
            return 1.0;
        }

        return max(0.0, 1.0 - ($this->distance($a, $b) / $maxLen));
    }

    /**
     * Grapheme cluster count — consistent with the distance() implementation.
     * ASCII strings return byte length (= char length = grapheme count).
     */
    public function graphemeLength(string $s): int
    {
        if (!preg_match('/[^\x00-\x7F]/', $s)) {
            return strlen($s);
        }

        return count($this->splitGraphemes($s));
    }

    /**
     * Splits a string into grapheme clusters using PCRE \X.
     * \X matches a Unicode extended grapheme cluster per the Unicode standard,
     * equivalent to grapheme_str_split() but without requiring a specific
     * ICU build that includes that function.
     *
     * @return string[]
     */
    private function splitGraphemes(string $s): array
    {
        if ($s === '') {
            return [];
        }

        preg_match_all('/\X/u', $s, $matches);

        return $matches[0];
    }
}
