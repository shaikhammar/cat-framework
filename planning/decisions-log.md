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

---

## D15: Arabic/Urdu clitic handling in terminology recognition

**Status:** Accepted
**Affects:** terminology
**Decision:** `SqliteTerminologyProvider::recognize()` uses strict word-boundary detection only (space/punctuation adjacent to match). No clitic prefix stripping is attempted. Arabic clitics (بـ ، الـ ، وـ ، فـ ، كـ ، للـ) that are written attached to a term (e.g., والترجمة for "and the translation") will not produce a match against the term ترجمة.
**Rationale:** Neither Okapi nor translate-toolkit solve this natively either — both delegate to external tools (Farasa, CAMeL Tools) for Arabic morphology. Those tools are Python-only, making them unsuitable as a dependency in this PHP library. A pure-PHP alternative (variant pre-generation at import time) would work but adds complexity that isn't justified until real termbase usage reveals how often clitic-attached matches are actually missed.
**Revisit when:** Users report missed terminology hits on Arabic/Urdu content where clitics are common. Upgrade path: at `addEntry()` and `import()` time, generate prefixed variants of each source term (for Arabic/Urdu language pairs only) and store them as additional rows marked `is_variant = 1`. The `recognize()` scan then hits these rows naturally with no change to the matching loop. Variants are excluded from `lookup()` results and not exported. Prefix list to generate: بـ ، الـ ، وـ ، فـ ، فالـ ، وال ، كـ ، للـ ، بالـ. No external dictionary validation needed — the termbase itself is the lexicon.

---

# Phase 3 Decisions

---

## D16: PSR-18 HTTP client injection for MT adapters

**Status:** Accepted
**Affects:** mt
**Decision:** MT adapters accept a `Psr\Http\Client\ClientInterface`, `Psr\Http\Message\RequestFactoryInterface`, and `Psr\Http\Message\StreamFactoryInterface` via constructor injection. The `catframework/mt` package requires `psr/http-client`, `psr/http-factory`, and `psr/http-message` (all interface packages, zero transitive deps). No concrete HTTP client is bundled or auto-discovered. A `suggest` block in `composer.json` points to `guzzlehttp/guzzle` and `symfony/http-client`. Each adapter ships a static `create(string $apiKey, ?ClientInterface $client = null): self` factory that optionally auto-detects a commonly installed client if none is injected, but throws a descriptive `RuntimeException` if no client is available.
**Rationale:** Keeps the framework dependency-free. Users in Laravel already have Guzzle; Symfony users already have Symfony HTTP Client. Forcing either on the other is hostile.

---

## D17: DeepL XML tag mode for InlineCode preservation

**Status:** Accepted
**Affects:** mt (DeepLAdapter)
**Decision:** Convert each InlineCode to `<x id="{N}"/>` XML placeholder before sending to DeepL. Send with `tag_handling=xml&ignore_tags=x`. Wrap the entire source string in `<seg>...</seg>` and build the XML string via `DOMDocument` (never string concatenation) to handle `&`, `<`, `>` in source text. Parse the response via `DOMDocument::loadXML()`. After decoding, compare sent vs. received placeholder IDs. If any are missing, annotate the returned Segment with `metadata["mt_tag_loss"] = [missingIds]`. Do not throw; return the degraded Segment with a warning.
**Rationale:** DeepL's XML mode is the most reliable tag-preservation mechanism available in any MT API. The encode/decode symmetry bug with unescaped XML characters is the primary failure mode — DOM construction eliminates it.

---

## D18: Google Translate strips InlineCodes

**Status:** Accepted
**Affects:** mt (GoogleTranslateAdapter)
**Decision:** Google Cloud Translation API v3 does not reliably preserve arbitrary XML tags even in `text/html` mode. Send plain text only (`mimeType: "text/plain"`). Strip all InlineCodes from the source Segment before sending. Return a Segment containing only the translated plain text with no InlineCodes. Document this limitation explicitly in the adapter's class docblock and in the package README. The QA `tag_consistency` check will flag missing codes as ERROR, prompting the translator to re-insert them.
**Rationale:** Incorrect tag positions in translated output (which HTML mode can produce) are worse than no tags at all. Missing tags are detectable by QA; misplaced tags are not.

---

## D19: XLSX shared strings — one SegmentPair per unique `<si>` index

**Status:** Accepted
**Affects:** filter-xlsx
**Decision:** Produce exactly one SegmentPair per unique shared string index that is referenced by at least one cell. Do not produce one SegmentPair per cell. Iterate `xl/sharedStrings.xml` to produce segments (not worksheet cells). Build a set of "referenced indices" by scanning all worksheets first. Skip unreferenced indices. On rebuild, replace the `<si>` in the skeleton's `sharedStrings.xml` — all cells referencing that index automatically get the same translated string. Delete `xl/calcChain.xml` from the rebuilt ZIP; Excel regenerates it on next open.
**Rationale:** Deduplication is correct behavior — if the source file had the same string in 50 cells, it should have the same translation in 50 cells. Producing 50 duplicate SegmentPairs would be confusing and wasteful.

---

## D20: PPTX extraction scope — slides and notes only, skip masters/layouts

**Status:** Accepted
**Affects:** filter-pptx
**Decision:** Extract translatable text from `ppt/slides/slide{N}.xml` and `ppt/notesSlides/notesSlide{N}.xml` only. Skip `ppt/slideMasters/`, `ppt/slideLayouts/`, `ppt/theme/`, and hidden slides (`show="0"` in presentation.xml). Apply the same explicit-only `<a:rPr>` comparison as D9 (no cascade resolution). Use the same run-merging and InlineCode generation logic as DocxFilter. If run-merging logic is duplicated across DocxFilter and PptxFilter, extract it to `CatFramework\Core\Util\OoxmlRunMerger` in `catframework/core` during the filter-xlsx implementation session.
**Rationale:** Masters and layouts are design templates, not translatable content for a specific presentation. Translating them would propagate changes to all slides sharing that master, which is not what a translator intends. Hidden slides are excluded by default because they may be draft content not intended for translation.
