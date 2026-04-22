# Fuzzy Matching Design

The algorithm that powers TM fuzzy matching. This is the hardest algorithmic problem in Phase 2.

---

## What fuzzy matching does

Given a source segment the translator is working on, find TM entries with similar (but not identical) source text. Return each match with a score from 0.0 to 1.0 indicating how similar it is. The translator uses the match as a starting point, editing only what changed.

Example:

```
Current segment:  "Click the Save button to save your changes."
TM entry source:  "Click the OK button to save your changes."
TM entry target:  "Cliquez sur le bouton OK pour enregistrer vos modifications."
Score: 0.91 (one word different out of nine)
```

The translator sees the 91% match, copies the target, and changes "OK" to "Enregistrer". Saves ~80% of the typing.

---

## Algorithm: Character-Level Levenshtein Distance

### Why Levenshtein

Levenshtein edit distance counts the minimum number of single-character insertions, deletions, and substitutions to transform one string into another. It's the industry standard for CAT tool fuzzy matching (Trados, memoQ, Wordfast all use variants of edit distance).

Alternatives considered and rejected:

- **Word-level edit distance:** Counts word operations instead of character operations. Produces scores that feel more intuitive ("one word changed = ~90% match") but breaks down for non-Latin scripts where word boundaries are ambiguous (Chinese, Japanese) or where a single character change in a word matters (Hindi verb conjugations). Character-level is universally applicable.
- **Longest Common Subsequence (LCS):** Similar to Levenshtein but doesn't count substitutions (only insertions and deletions, at double cost). Slightly different scores but no meaningful advantage. Levenshtein is simpler and more widely understood.
- **Damerau-Levenshtein:** Adds transposition as a fourth operation (swap two adjacent characters). Useful for typo detection but not for translation matching. Adds implementation complexity for negligible benefit.
- **TF-IDF / cosine similarity:** Token-based, good for document-level similarity but too coarse for sentence-level TM matching. Misses word order changes.

### UTF-8 handling

PHP's built-in `levenshtein()` operates on bytes, not characters. For ASCII text this is fine. For multibyte text it's wrong:

```
"café" vs "cafe"
Byte-level: levenshtein("caf\xC3\xA9", "cafe") = 2 (two bytes changed)
Character-level: levenshtein("café", "cafe") = 1 (one character substituted)
```

For Hindi and Urdu, the problem is worse. A single visible character (grapheme cluster) can be multiple Unicode code points:

```
"कि" (ki) = क (ka, U+0915) + ि (i vowel sign, U+093F) = 2 code points, 1 visible character
```

We need to operate at the **grapheme cluster** level, not the byte or code point level.

### Implementation

```
Class: CatFramework\TranslationMemory\LevenshteinCalculator

Method: distance(string $a, string $b): int

Approach:
1. Split both strings into grapheme clusters using grapheme_str_split()
   (from ext-intl, which wraps ICU's grapheme break iterator).
2. Standard dynamic programming Levenshtein with single-row optimization
   (O(min(m,n)) space instead of O(m*n)).
3. Return the integer edit distance.

Method: similarity(string $a, string $b): float

1. Compute distance.
2. Return 1.0 - (distance / max(graphemeCount($a), graphemeCount($b))).
3. If both strings are empty, return 1.0.
```

**Why `grapheme_str_split` and not `mb_str_split`:**

`mb_str_split` splits by code points. A Hindi character with a combining vowel sign would be split into two elements, and the Levenshtein algorithm would treat them as separate units. `grapheme_str_split` splits by what the user sees as a single character. This is correct for Hindi, Urdu, Arabic (with diacritics), Korean (with Jamo composition), and emoji (with ZWJ sequences).

`grapheme_str_split` requires `ext-intl`, which is already a dependency of `catframework/core`.

### Performance characteristics

- Time: O(m * n) where m, n are grapheme counts of the two strings.
- Space: O(min(m, n)) with single-row optimization.
- Typical segment: 50-150 characters. Worst case: ~150 * 150 = 22,500 operations per comparison. In PHP, this takes < 1ms.
- The bottleneck is not individual comparisons but the number of comparisons. See "Pre-filtering" below.

---

## Scoring

### Formula

```
score = 1.0 - (levenshteinDistance / max(len(source), len(candidate)))
```

Where `len` is grapheme cluster count.

### Why `max(source, candidate)` in the denominator

Using `max` instead of `source length` or `average` ensures the score is symmetric and never exceeds 1.0. If we used only source length, a very long TM entry matching a short source could produce a score > 1.0 (nonsensical) or a misleadingly high score.

### Match type classification

| Score | Type | Meaning |
|---|---|---|
| 1.0, codes match | EXACT | Identical text and InlineCode structure |
| 1.0, codes differ | EXACT_TEXT | Identical plain text, different formatting |
| 0.70 - 0.99 | FUZZY | Partial match, useful for editing |
| < 0.70 | Not returned | Below threshold, not useful |

The 0.70 (70%) default threshold matches industry convention. Matches below 70% typically require more editing effort than translating from scratch.

---

## InlineCode handling during matching

### The problem

TM entries store Segments (with InlineCodes). The source segment being matched also has InlineCodes. How do codes affect matching?

### Strategy: strip codes for scoring, verify separately for classification

1. **For scoring:** Extract plain text from both source and candidate (`Segment::getPlainText()`). Compute Levenshtein on plain text only. InlineCodes do not affect the fuzzy score.

2. **For match type classification:**
   - If score = 1.0 AND source/candidate have identical InlineCode structure (same IDs, same types, same positions relative to text): `EXACT`.
   - If score = 1.0 AND plain text matches but InlineCode structure differs: `EXACT_TEXT`.
   - If score < 1.0: `FUZZY` (code structure is irrelevant for fuzzy matches; the translator will adjust codes manually).

3. **For presenting the match:** The target Segment from the TM entry is shown as-is, with its original InlineCodes. If the source segment has different codes, the editor or QA check flags the mismatch. The matching engine does not attempt to remap codes automatically (this is error-prone and better left to the translator).

### Why not include codes in the distance calculation

Including codes would penalize formatting changes that don't affect the translation. "Click **OK** to save" vs "Click OK to save" (same text, one has bold) would score < 1.0 even though the translation is identical. Stripping codes before scoring ensures formatting-only changes get a 1.0 EXACT_TEXT match, which is the correct behavior.

---

## Pre-filtering for performance

### The problem

A TM with 100,000 entries. Computing Levenshtein against every entry for each lookup takes 100,000 * ~0.5ms = 50 seconds. Unacceptable for interactive use (target: < 200ms per lookup).

### Strategy: length-based pre-filtering

**Observation:** If two strings have very different lengths, their Levenshtein score is necessarily low. Specifically, the minimum possible distance between strings of length m and n is `|m - n|` (you need at least that many insertions or deletions). Therefore:

```
maxScore = 1.0 - (|m - n| / max(m, n))
```

If `maxScore < minScore`, this candidate cannot possibly meet the threshold. Skip it.

For the default threshold of 0.7 and a source of length 100:

```
0.7 ≤ 1.0 - (|100 - n| / max(100, n))
```

Solving: n must be in roughly [70, 143]. So we only compute Levenshtein for entries with character count between 70 and 143. For a TM with 100k entries uniformly distributed across lengths, this might filter down to ~5-10k candidates.

### Implementation in SQLite

```sql
-- char_count column stores grapheme count of source plain text
-- Indexed: CREATE INDEX idx_tm_filter ON translation_units(source_lang, target_lang, char_count)

SELECT * FROM translation_units
WHERE source_lang = :sourceLang
  AND target_lang = :targetLang
  AND char_count BETWEEN :minLen AND :maxLen
```

The `minLen` and `maxLen` are computed from the source segment's length and the minimum score threshold:

```php
$sourceLen = grapheme_strlen($sourcePlainText);
$minLen = (int) ceil($sourceLen * $minScore);
$maxLen = (int) floor($sourceLen / $minScore);
```

This SQL query uses the index and returns a small candidate set. Levenshtein is computed only against these candidates in PHP.

### Expected performance

| TM size | Candidates after filter (est.) | Levenshtein time (est.) |
|---|---|---|
| 10,000 | 500-1,500 | 5-15ms |
| 50,000 | 2,500-7,500 | 25-75ms |
| 100,000 | 5,000-15,000 | 50-150ms |
| 500,000 | 25,000-75,000 | 250-750ms |

For TMs up to 100k entries, this is within the 200ms target. For 500k+ entries, n-gram indexing would be needed (Phase 3 optimization).

---

## Normalization pipeline

Before comparing two strings, both are normalized to remove superficial differences.

### Default pipeline

Applied in order:

1. **NFC normalization** (`Normalizer::normalize($text, Normalizer::FORM_C)`): Ensures composed characters are canonical. "é" (U+0065 + U+0301) and "é" (U+00E9) become the same form. Critical for Hindi/Urdu where combining marks are common.

2. **Lowercase** (`mb_strtolower($text, 'UTF-8')`): "Save" and "save" should match. Note: for scripts without case (Hindi, Urdu, Arabic, Chinese, Japanese), this is a no-op. That's fine.

3. **Collapse whitespace** (`preg_replace('/\s+/u', ' ', $text)`): Tabs, multiple spaces, and other whitespace variants become single spaces. Prevents false mismatches from trivial formatting differences.

4. **Trim** (`trim($text)`): Remove leading/trailing whitespace.

### Design: pipeline is configurable

```php
$tm = new SqliteTranslationMemory($pdoConnection);
$tm->setNormalizers([
    new NfcNormalizer(),
    new LowercaseNormalizer(),
    new WhitespaceNormalizer(),
    new TrimNormalizer(),
]);
```

Each normalizer is a simple callable or interface implementer. Users can add custom normalizers (e.g., strip punctuation for aggressive matching in specific domains) or remove default ones (e.g., disable lowercase for case-sensitive legal text).

The normalized source text is stored alongside the original in the database. This avoids re-normalizing on every lookup.

---

## Lookup flow (complete)

```
Input: Segment $source, string $sourceLang, string $targetLang, float $minScore = 0.7, int $maxResults = 5

1. Extract plain text: $sourceText = $source->getPlainText()
2. Normalize: $normalizedSource = $this->normalize($sourceText)
3. Compute length: $sourceLen = grapheme_strlen($normalizedSource)
4. Compute length bounds: $minLen, $maxLen from $sourceLen and $minScore
5. Query SQLite: SELECT candidates WHERE lang pair matches AND char_count BETWEEN $minLen AND $maxLen
6. For each candidate:
   a. Compute: $distance = LevenshteinCalculator::distance($normalizedSource, $candidate->normalizedText)
   b. Compute: $score = 1.0 - ($distance / max($sourceLen, $candidate->charCount))
   c. If $score >= $minScore: add to results
7. Sort results by score descending
8. Classify match types:
   - Score 1.0 + codes match → EXACT
   - Score 1.0 + codes differ → EXACT_TEXT
   - Score < 1.0 → FUZZY
9. Truncate to $maxResults
10. Update last_used_at for returned TUs
11. Return MatchResult[]
```

---

## Future optimizations (Phase 3, not now)

- **N-gram indexing:** Store character trigrams for each TU. At query time, compute trigrams of source, use SQL to find TUs sharing a minimum number of trigrams. Reduces candidate set dramatically for large TMs.
- **Token-based pre-filtering:** Additionally filter by shared word tokens (candidates sharing < 50% of source words are unlikely to score > 0.7).
- **Caching:** LRU cache for repeated lookups (common when auto-propagating translations to identical segments).
- **Penalty weighting:** Weight moved words or structural changes differently from simple substitutions. Used by some tools for more "linguistically aware" scoring.
