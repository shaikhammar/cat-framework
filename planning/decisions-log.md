# Architecture Decisions Log

Decisions locked in during planning phase. Reference these before implementation to avoid re-debating settled questions.

---

## D1: Add `isIsolated` to InlineCode

**Status:** Accepted
**Affects:** core, segmentation, xliff
**Decision:** InlineCode gets a `bool $isIsolated = false` property. The segmentation engine sets it to `true` when creating synthetic open/close codes for tags that span a sentence boundary. De-segmentation uses it to re-join spanning tags. XLIFF serializer maps isolated codes to `<it>` elements.
**Applied:** Updated in 02-core-data-models.md.

---

## D2: External skeleton files

**Status:** Accepted
**Affects:** xliff, all filters
**Decision:** Skeletons are stored as separate files alongside the XLIFF, not embedded in it. Convention: if the XLIFF is `project.xlf`, the skeleton is `project.xlf.skl`. The XLIFF `<skeleton>` element references the external file. The skeleton format is filter-specific and opaque to the XLIFF serializer.
**Rationale:** HTML skeletons are small strings, but DOCX skeletons (Phase 2) are entire ZIP archives. Embedding won't scale. Using external files from day one avoids a redesign.

---

## D3: Hindi/Urdu SRX rules with correct punctuation

**Status:** Accepted
**Affects:** segmentation, srx (default rules)
**Decision:** Default SRX rules include sentence-ending punctuation for Hindi (purna viram U+0964, double danda U+0965), Urdu/Arabic (Arabic full stop U+06D4, Arabic question mark U+061F), plus standard `.?!`. The afterBreak pattern for non-Latin scripts uses `\s+\p{L}` (any letter after whitespace) instead of `\s+\p{Lu}` (uppercase only). Accept that this is a weaker heuristic.
**Validation:** Test with real Hindi and Urdu text before Phase 1 is considered complete.

---

## D4: Pluggable ICU segmenter (design for, don't build)

**Status:** Accepted
**Affects:** segmentation interface design
**Decision:** The `SegmentationEngineInterface` is already implementation-agnostic. An ICU-backed implementation using `IntlBreakIterator::createSentenceInstance()` can satisfy the same interface. Do not build it in Phase 1. The SRX-based engine is the primary implementation. If SRX proves inadequate for specific languages, the ICU implementation becomes Phase 2 work.

---

## D5: XML entity escaping for InlineCode.data in XLIFF

**Status:** Accepted
**Affects:** xliff
**Decision:** InlineCode.data is stored inside XLIFF `<bpt>`/`<ept>`/`<ph>` elements using standard XML entity escaping (`&lt;`, `&gt;`, `&amp;`). Not CDATA.
**Rationale:** CDATA sections can't be nested and break on `]]>`. Entity escaping is verbose but universally safe and most likely to survive round-tripping through third-party CAT tools.

---

## D6: Custom namespace for displayText in XLIFF 1.2

**Status:** Accepted
**Affects:** xliff
**Decision:** `InlineCode.displayText` is stored as a custom namespaced attribute: `catfw:equiv-text` on `<bpt>`/`<ept>`/`<ph>` elements. XLIFF root declares `xmlns:catfw="urn:catframework"`. Other tools will either preserve unknown namespaced attributes (most do) or strip them (acceptable, displayText is regenerable from the code's data or auto-generated as `{1}`, `{2}`).

---

## D7: Isolated codes as `<it>` in XLIFF 1.2

**Status:** Accepted
**Affects:** xliff, segmentation
**Decision:** Isolated InlineCodes (created by segmentation spanning) are serialized as XLIFF 1.2 `<it pos="open">` or `<it pos="close">` elements, per the XLIFF spec. Accept that some third-party tools handle `<it>` poorly. This is a known XLIFF 1.2 limitation that XLIFF 2.0's `<sc>`/`<ec>` model solves. Document the limitation.

---

## D8: Round-trip test suite from day one

**Status:** Accepted
**Affects:** all packages
**Decision:** Every filter and the XLIFF serializer gets a round-trip test: extract a test file → save to XLIFF → reload from XLIFF → rebuild the file → compare against expected output. This is the highest-value test investment in the project. Build it alongside each component, not after.

---

# Phase 2 Decisions

---

## D9: DOCX rPr comparison (explicit only, ignore inheritance)

**Status:** Accepted
**Affects:** filter-docx
**Decision:** Compare only explicit `<w:rPr>` elements when merging runs. Do not resolve style inheritance. This may produce extra InlineCodes for runs that are visually identical but differ in explicit vs. inherited formatting. This is the safe default: extra codes are cosmetic noise, but incorrect merges produce wrong formatting on rebuild.
**Revisit when:** Average InlineCode density exceeds 4 codes per segment across a corpus of 10+ real DOCX files. At that point, implement style-aware merging: resolve effective formatting by walking the style hierarchy (run rPr → paragraph style → document defaults via styles.xml). This is substantial work (~1-2 sessions) but produces significantly cleaner segments.

---

## D10: Two-tier Levenshtein (native ASCII, grapheme multibyte)

**Status:** Accepted
**Affects:** translation-memory
**Decision:** If both strings are ASCII-only (no bytes > 127), use PHP's native `levenshtein()` for C-level speed. Otherwise, pre-split into grapheme arrays via `grapheme_str_split()` and run DP on arrays. Add a word-level Jaccard pre-filter only if profiling shows the length pre-filter alone is insufficient for 100k+ TMs.
**Revisit when:** P95 lookup time exceeds 200ms with a real TM. Likely trigger: TM > 100k entries or highly uniform segment lengths (UI string TMs where most entries are 30-80 chars). Upgrade path: (1) add word-level Jaccard pre-filter (~5 lines, cuts candidates 50-70%), (2) if still slow, add n-gram trigram indexing in SQLite (new table + JOIN query, ~1 session).

---

## D11: DOCX skeleton via string replacement

**Status:** Accepted
**Affects:** filter-docx
**Decision:** Create the skeleton by string-level replacement within the ZIP's XML files (replace `<w:t>` content with placeholder tokens), not by DOM parsing and re-serialization. This preserves byte-level fidelity of all non-text content (namespaces, formatting, whitespace). DOM is used only during extraction to understand structure, not to modify the skeleton.
**Revisit when:** Round-trip tests fail on a DOCX where `<w:t>` content contains XML entities (`&amp;`, `&lt;`) or CDATA sections that break the string replacement. Fallback: switch to DOM-based skeleton creation with namespace-preserving serialization (`DOMDocument::saveXML()` using the original root element). This is safer but risks namespace/whitespace drift, so add byte-level non-text content comparison to the test suite.

---

## D12: Term recognition via brute-force mb_strpos

**Status:** Accepted
**Affects:** terminology
**Decision:** Load all source terms into memory. For each `recognize()` call, iterate terms and check with `mb_strpos`. Case-insensitive for Latin scripts (pre-lowercase both sides), exact for non-Latin. Word boundary checks via space/punctuation detection (not regex `\b` which is unreliable for Arabic/Hindi).
**Revisit when:** `recognize()` exceeds 50ms per segment with a termbase of 5k+ entries. Upgrade path: Aho-Corasick automaton built at initialization, single-pass O(textLength + matches) scan regardless of term count. For Arabic clitic handling: generate term variants with common prefixes (ب، ال، ف، و) and add to the automaton. Verify Packagist for existing Aho-Corasick implementations before writing one.

---

## D13: RTL handling in DOCX rebuild

**Status:** Accepted
**Affects:** filter-docx
**Decision:** When the target language is RTL (prefix: ar, he, fa, ur), add `<w:bidi/>` to paragraph properties and `<w:rtl/>` to run properties during rebuild. Simple heuristic based on language code. Per-paragraph direction detection deferred.
**Revisit when:** Users report incorrect directionality in mixed-direction documents (e.g., English quotes inside Urdu text, or LTR product names inside RTL paragraphs). Upgrade path: per-run direction detection using Unicode Bidi Algorithm (UAX #9) via `ext-intl`. Apply `<w:rtl/>` only to runs containing RTL characters, not blanket per-language. This is significantly more complex but handles mixed content correctly.

---

## D14: Tracked changes in DOCX (extract current text only)

**Status:** Accepted
**Affects:** filter-docx
**Decision:** Extract text inside `<w:ins>` (visible insertions). Ignore text inside `<w:del>` (deleted text). Ignore `<w:rPrChange>` (formatting change tracking). On rebuild, produce plain runs with no tracked change markup. This is standard CAT tool behavior (equivalent to "accept all changes" before translation).
**Revisit when:** Users need to preserve tracked changes through translation (e.g., legal documents where revision history is contractually required). Upgrade path: extract both inserted and deleted text as separate segment types (flagged in SegmentPair context), translate both, reconstruct `<w:ins>`/`<w:del>` markup on rebuild with original author/date attributes preserved from skeleton. This is a significant feature (~2-3 sessions) and may require a new SegmentPair property to distinguish insert/delete/current text.
