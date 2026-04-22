# Phase 2: Risks and Hard Problems

Four technical risks specific to Phase 2. Same format as Phase 1: what the problem is, why it's hard, what to decide now, what to defer.

---

## Risk 1: DOCX run merging produces incorrect InlineCode boundaries

### What the problem is

The DOCX filter must merge adjacent runs with identical formatting into a single text span, then create InlineCode pairs at formatting transitions. If the merging logic is wrong, the filter produces:

- **Too many InlineCodes:** Every run becomes a code pair even when formatting hasn't changed. The translator sees `{1}Hello{/1} {2}world{/2}` instead of `Hello world`. The segment is cluttered and hard to work with.
- **Wrong InlineCode boundaries:** A formatting change is missed, and two differently-formatted spans are merged. The rebuild produces text with incorrect formatting (bold text that should be normal, or vice versa).

### Why it is hard

1. **rPr comparison is non-trivial.** XML attribute order is undefined. Two `<w:rPr>` elements that look different as strings may be semantically identical. The normalization approach (sort children and attributes) handles this, but subtle differences can still cause false negatives. Example: `<w:rFonts w:ascii="Calibri"/>` vs. no `<w:rFonts>` when the document default font is Calibri. These render the same, but the explicit vs. implicit forms don't match.

2. **Style inheritance creates invisible formatting.** A run with no `<w:rPr>` may be bold because its paragraph style is bold. Another run with explicit `<w:rPr><w:b/></w:rPr>` is also bold. They look the same to the reader but have different rPr XML. The Phase 2 decision to compare only explicit rPr (ignore inheritance) is safe but produces extra codes.

3. **Word's inconsistent serialization.** Different versions of Word (and different save operations in the same version) produce different run boundaries for the same visible text. There's no spec for when Word splits runs. This means the same document edited and re-saved may extract differently each time.

### Decision needed now

**Accept extra InlineCodes as the safe default. Optimize later.**

The Phase 2 filter compares explicit `<w:rPr>` only. It does not resolve style inheritance. This means some paragraphs will have more InlineCodes than strictly necessary. This is better than the alternative (resolving inheritance incorrectly and producing wrong formatting on rebuild).

A Phase 3 optimization can add style-aware merging: resolve the effective formatting of each run by walking the style hierarchy (`<w:pPr>` → `<w:pStyle>` → `styles.xml` → document defaults). This is substantial work but would produce cleaner segments.

### What to measure

Track the "code density" metric: average number of InlineCodes per segment across a test corpus of real DOCX files. If it's consistently > 4 codes per segment, the rPr comparison is too conservative and needs refinement. If it's 1-2, it's working well.

---

## Risk 2: Character-level Levenshtein performance for large TMs

### What the problem is

The fuzzy matching algorithm computes character-level Levenshtein distance for every candidate that passes the length pre-filter. For a TM with 100k entries and a pre-filter that passes 10%, that's 10,000 Levenshtein computations per lookup. Each computation is O(m*n) where m and n are segment lengths in grapheme clusters.

PHP is not fast at tight loops with function calls. `grapheme_substr` is slower than raw array access. A naive implementation may not hit the < 200ms target for interactive use.

### Why it is hard

1. **Grapheme iteration is expensive.** `grapheme_str_split` calls ICU's break iterator for every string. For 10,000 candidates, that's 20,000 break iterator initializations (source + candidate). This overhead may dominate the actual DP computation.

2. **No native fast path.** PHP's built-in `levenshtein()` is C-level fast but byte-based. We can't use it for multibyte text. There's no `ext-intl` function for grapheme-level edit distance. The implementation must be pure PHP.

3. **The length pre-filter's selectivity depends on TM distribution.** If most TM entries are roughly the same length (common for UI strings), the pre-filter passes a large percentage and doesn't help much.

### Decision needed now

**Implement in two tiers: fast path for ASCII, slow path for multibyte.**

```
1. Check if both strings are ASCII-only (no bytes > 127).
   - If yes: use PHP's native levenshtein(). O(m*n) in C, ~100x faster than PHP.
   - If no: fall through to grapheme-level implementation.

2. For the grapheme-level implementation:
   - Pre-split both strings into grapheme arrays (one call to grapheme_str_split each).
   - Run DP on the arrays (array index access, no per-character function calls).
   - Single-row optimization for O(min(m,n)) space.
```

The ASCII fast path covers English-English TM lookups (the most common case) with C-level performance. Hindi/Urdu/Arabic lookups use the slower path but benefit from the pre-split optimization (one `grapheme_str_split` call instead of per-character `grapheme_substr`).

### Fallback plan

If the grapheme-level implementation is still too slow for large TMs (> 50k entries), implement a word-level pre-filter before character-level Levenshtein:

1. Tokenize source and candidate into words.
2. Compute Jaccard similarity (intersection / union of word sets).
3. If Jaccard < 0.5, skip (strings sharing fewer than half their words can't score > 0.7 at character level in practice).
4. Only compute character-level Levenshtein for candidates passing the word filter.

This adds ~5 lines of code and can reduce candidates by another 50-70%. Defer this unless profiling shows the length pre-filter alone isn't enough.

### What to measure

Benchmark with a real-world TM export (you should have TMX files from your translation work in Wordfast/Trados). Test with TM sizes of 10k, 50k, 100k entries. Measure P95 lookup time. Target: < 200ms at 100k entries.

---

## Risk 3: DOCX skeleton fidelity for complex documents

### What the problem is

The skeleton is a copy of the original DOCX ZIP with text replaced by placeholder tokens. During rebuild, placeholders are replaced with translated content. Any corruption of the skeleton produces a broken DOCX that Word cannot open.

### Why it is hard

1. **XML namespace handling.** OOXML uses multiple namespaces (`w`, `r`, `mc`, `wp`, etc.). When modifying document.xml with `DOMDocument`, the serializer may add redundant namespace declarations or reformat the XML. Word is picky about namespace declarations on the root element; unexpected changes can make the file unreadable.

2. **ZIP entry ordering and compression.** The ZIP format doesn't guarantee entry order, but some DOCX consumers expect `[Content_Types].xml` to be the first entry. PHP's `ZipArchive` when modifying entries may change compression levels or entry order. If the output ZIP doesn't match expectations, Word may reject it.

3. **Relationship ID integrity.** If the filter modifies XML that contains relationship references (`r:id`), and the corresponding `.rels` file is inconsistent, Word reports corruption. The filter must never modify or remove relationship references that it doesn't fully understand.

4. **Large documents with embedded media.** A DOCX with many images (e.g., a product catalog) could be 50-100MB. The skeleton is the same size. Extracting and rewriting the ZIP must not load the entire archive into memory.

### Decision needed now

**Minimize XML modifications. Use string replacement, not DOM serialization, for the skeleton.**

Instead of parsing the XML with DOM and re-serializing (which may change formatting, namespaces, and whitespace), use a targeted approach:

1. Read the XML file as a raw string from the ZIP.
2. During extraction, record the exact byte offsets of translatable text within `<w:t>` elements.
3. For the skeleton, do string-level replacement: replace the text between `<w:t>` and `</w:t>` with a placeholder token, preserving everything else byte-for-byte.
4. During rebuild, replace placeholder tokens with translated text using string replacement.

This avoids DOM round-trip issues entirely. The XML structure is never parsed and re-serialized for the skeleton; it's manipulated as a string. Only the extraction step uses DOM (for understanding the structure), and extraction's DOM output is used only to build the Segment, not to write back to the file.

**Tradeoff:** String replacement is fragile if `<w:t>` elements contain XML entities or CDATA. In practice, Word always writes plain text in `<w:t>` (entities only for `&`, `<`, `>` which it rarely uses in text content). This approach works for 99%+ of real DOCX files.

**Fallback:** If string replacement proves unreliable, fall back to DOM-based skeleton creation with careful namespace preservation (serialize with `DOMDocument::saveXML()` using the original document's root element to preserve namespace declarations). Add round-trip tests that verify byte-level preservation of non-text content.

### What to measure

Build a test suite with 10+ real DOCX files covering: simple documents, complex formatting, tracked changes, embedded images, tables, headers/footers, RTL content. For each, extract → set trivial translations (copy source) → rebuild → open in Word/LibreOffice → verify no corruption. Automate with LibreOffice headless mode in CI.

---

## Risk 4: Term recognition performance and accuracy for non-Latin scripts

### What the problem is

The terminology provider's `recognize()` method scans source text for known terms. For a termbase with 5,000 entries and a segment of 100 characters, that's 5,000 substring searches per segment. With 500 segments in a document, that's 2.5 million searches.

### Why it is hard

1. **Word boundary detection for non-Latin scripts.** English word boundaries are clear (spaces, punctuation). Hindi words are space-separated but compound words and sandhi (word joining) are common. Arabic is space-separated but has clitics (prefixed articles, prepositions) that attach to words. A term like "الحاسوب" (computer) should match inside "بالحاسوب" (with the computer), but a naive space-delimited search won't find it.

2. **Case-insensitive matching for Latin, exact for non-Latin.** The recognize method needs different matching strategies per script. A single `stripos` or `mb_stripos` call doesn't handle this correctly for mixed-script content.

3. **Overlapping matches.** The term "hard drive" and the term "hard" both appear in "check the hard drive." The recognizer should return both matches, not just the longest one or the first one.

4. **Performance at scale.** For each segment, checking every term with `mb_strpos` or `preg_match` is O(terms * segmentLength). With 5k terms and 500 segments, this could take seconds.

### Decision needed now

**Two-phase implementation: simple first, optimize if needed.**

Phase 2 (ship with):
- Load all source terms for the language pair into memory at initialization.
- For each `recognize()` call, iterate terms and use `mb_strpos` (case-insensitive for Latin via `mb_strtolower` pre-processing, exact for non-Latin).
- Word boundary check: verify the character before and after the match position is a word boundary (`\b` equivalent). For Latin: space, punctuation, or string start/end. For Arabic/Hindi: space or string start/end only (skip `\b` which doesn't work correctly for these scripts in PHP regex).
- Return all matches including overlapping ones.

Phase 3 (optimize if profiling shows a bottleneck):
- Build an Aho-Corasick automaton from the term list at initialization time. Single-pass scan of the text finds all term matches in O(textLength + matches), regardless of the number of terms. PHP implementations exist on Packagist (would need to verify), or a simple trie-based implementation is ~100 lines.
- For Arabic clitic handling: add a pre-processing step that generates term variants with common prefixes (ب، ال، ف، و، etc.) and adds them to the automaton.

### What to measure

Benchmark `recognize()` with a real termbase (1k, 5k, 10k entries) against real source segments. Target: < 50ms per segment for 5k terms. If the brute-force approach exceeds this, implement Aho-Corasick.

---

## Summary: Decisions Needed Now

| # | Decision | Recommended choice |
|---|---|---|
| 1 | DOCX rPr comparison strategy | Compare explicit rPr only (ignore inheritance). Accept extra InlineCodes. Optimize in Phase 3. |
| 2 | Levenshtein implementation | Two-tier: native `levenshtein()` for ASCII, grapheme-level DP for multibyte. Add word-level pre-filter if needed. |
| 3 | DOCX skeleton modification approach | String-level replacement, not DOM re-serialization. Preserves byte-level fidelity of non-text content. |
| 4 | Term recognition strategy | Brute-force `mb_strpos` per term. Aho-Corasick in Phase 3 if profiling demands it. |
| 5 | RTL handling in DOCX rebuild | Add `<w:bidi/>` and `<w:rtl/>` based on target language code prefix (ar, he, fa, ur). |
| 6 | Tracked changes in DOCX | Extract current visible text only. Strip tracked change markup on rebuild. |
