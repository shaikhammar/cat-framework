# Phase 2: Scope and Build Order

Phase 1 delivered the pipeline: file in → segments → translations → XLIFF save/load → file out. Phase 2 makes it a real CAT framework by adding the features translators depend on daily: translation memory, DOCX support, quality assurance, and terminology.

---

## Packages in Phase 2

Five packages, two of which are high priority:

| # | Package | Priority | Depends on |
|---|---|---|---|
| 1 | `catframework/tmx` | High | core |
| 2 | `catframework/translation-memory` | High | core, tmx |
| 3 | `catframework/filter-docx` | High | core |
| 4 | `catframework/qa` | Medium | core |
| 5 | `catframework/terminology` | Medium | core |

---

## Build Order and Features

### Step 1: catframework/tmx

**TMX 1.4b parser/writer. Needed before translation-memory can import/export.**

Features:

- `TmxReader`: parse TMX XML into an iterable of TranslationUnit objects.
  - DOM mode for small/medium files (< 10k TUs, fits in memory).
  - Streaming mode via `ext-xmlreader` for large files (100k+ TUs). Yields one TranslationUnit at a time without loading the full DOM.
  - Map TMX `<tu>` to TranslationUnit model.
  - Map TMX inline elements (`<bpt>`, `<ept>`, `<ph>`, `<it>`) to InlineCode objects. Same mapping logic as XliffReader (XLIFF and TMX share the same inline element vocabulary).
  - Parse `<prop>` elements into TranslationUnit::$metadata. Standard props: `x-createdBy`, `x-project`, `x-domain`. Custom props preserved as-is.
  - Parse `<note>` elements into metadata under a `notes` key.
  - Handle `xml:lang` on `<tuv>` for language identification.
  - Validate: reject files missing required `<header>` or with no `<body>`.
- `TmxWriter`: write a collection of TranslationUnits to TMX 1.4b XML.
  - Reverse of the reader. TranslationUnit → `<tu>` with `<tuv>` pairs.
  - InlineCode → TMX inline elements (same mapping as XliffWriter).
  - Write metadata as `<prop>` elements.
  - Include `<header>` with creation tool, creation date, source language.
  - UTF-8 output, XML declaration, proper escaping.

**Not in this step:**
- No TMX 2.0 (not widely adopted, 1.4b is the standard).
- No `<seg>` subelements beyond inline codes (TMX supports `<hi>` for highlighting, `<sub>` for subflows; these are rare in practice).
- No character-set negotiation (everything is UTF-8).

**External deps:** `ext-dom`, `ext-xmlreader`, `ext-libxml`.

Estimated effort: 1-2 sessions.

---

### Step 2: catframework/translation-memory

**The highest-value Phase 2 package. TM is what makes a CAT tool productive.**

Features:

- `SqliteTranslationMemory`: implements `TranslationMemoryInterface` with SQLite backend via PDO.
  - Schema: `translation_units` table with columns for id, source_text (plain), target_text (plain), source_segments (JSON-serialized Segment with codes), target_segments (JSON-serialized Segment with codes), source_lang, target_lang, char_count, word_count, created_at, last_used_at, created_by, metadata (JSON).
  - `store()`: insert or update (most-recent-wins on duplicate source text + language pair).
  - `lookup()`: two-phase matching:
    1. **Exact match:** Normalized plain text comparison. Normalize = trim, collapse whitespace, NFC Unicode normalization via `Normalizer::normalize()`. If exact text match found, additionally verify InlineCode structure. Score: 1.0 (EXACT) if codes match, 0.99 (EXACT_TEXT) if only text matches.
    2. **Fuzzy match:** Character-level Levenshtein distance on plain text. Pre-filter by character count (±30% of source length for default 0.7 threshold). Score: `1.0 - (distance / max(sourceLen, candidateLen))`. Return candidates above `$minScore`, sorted descending.
  - `import()`: read TMX file via catframework/tmx, store each TU. Return count.
  - `export()`: iterate all TUs, write via TmxWriter. Return count.
  - `update lastUsedAt`: when a TU is returned as a match result, update its `last_used_at` timestamp.
  - Index: SQLite index on `source_lang, target_lang, char_count` for fast pre-filtering.

- `LevenshteinCalculator`: standalone utility class for UTF-8-safe edit distance.
  - Character-level (not byte-level) Levenshtein using `mb_substr`.
  - Operates on grapheme clusters via `grapheme_strlen` / `grapheme_substr` (from `ext-intl`) for correct Hindi/Urdu handling where a single visible character may be multiple Unicode code points.
  - Dynamic programming matrix, O(m*n) time and O(min(m,n)) space (single-row optimization).
  - Input segments are typically < 200 characters, so performance is acceptable for individual comparisons. The pre-filter limits how many comparisons are needed.

- Normalization pipeline: configurable sequence of normalizers applied before comparison.
  - Default: NFC normalization → lowercase → collapse whitespace → strip leading/trailing whitespace.
  - Each step is a simple callable. Users can add custom normalizers (e.g., strip punctuation for aggressive matching).

**Not in this step:**
- No n-gram indexing. Length-based pre-filtering is sufficient for TMs up to ~100k entries. N-gram indexing is a Phase 3 optimization if needed.
- No penalty weighting (e.g., penalizing moved words more than substitutions). Standard Levenshtein treats all operations equally. Custom distance functions can be swapped in later.
- No context matching (using surrounding segments to boost relevance). Phase 3.
- No concurrent access. SQLite with WAL mode supports concurrent reads but single writer. Sufficient for single-user and small-team scenarios. A MySQL/PostgreSQL adapter is a future option for multi-user deployments.

**External deps:** `ext-pdo`, `ext-pdo_sqlite`, `ext-intl` (for Normalizer and grapheme functions).

Estimated effort: 3-4 sessions. Fuzzy matching and the normalization pipeline are the time sinks. See Deliverable 2 for the detailed algorithm design.

---

### Step 3: catframework/filter-docx

**The most complex filter. Also the most useful for real translation work.**

Features:

- `DocxFilter`: implements `FileFilterInterface`.
  - `supports()`: matches `.docx` extension and OOXML MIME type.
  - `extract()`:
    - Open ZIP via `ext-zip`.
    - Parse `word/document.xml` (main body), `word/header{N}.xml`, `word/footer{N}.xml`, `word/footnotes.xml`, `word/endnotes.xml`.
    - Walk each XML file's paragraph (`<w:p>`) elements.
    - Within each paragraph, iterate runs (`<w:r>`). Each run has optional formatting properties (`<w:rPr>`) and text (`<w:t>`).
    - **Run merging:** Adjacent runs with identical `<w:rPr>` are merged into a single text span. This eliminates Word's habit of splitting text across runs for no visible reason (revision artifacts, spell-check boundaries, etc.).
    - **InlineCode generation:** When formatting changes between merged run groups, create InlineCode pairs. Example: normal text → bold text → normal text becomes `["Hello ", InlineCode(bold_open), "world", InlineCode(bold_close), " today"]`. The InlineCode `data` property stores the serialized `<w:rPr>` XML so rebuild can reconstruct exact formatting.
    - **Paragraph = SegmentPair:** Each `<w:p>` becomes one SegmentPair (source = paragraph content with InlineCodes, target = null).
    - **Hyperlinks:** `<w:hyperlink>` elements are extracted. Display text is translatable. The URL is stored in context (relationship ID → actual URL from `word/_rels/document.xml.rels`). An InlineCode wraps the hyperlink display text.
    - **Tables:** `<w:tbl>` → `<w:tr>` → `<w:tc>` → `<w:p>`. Standard paragraph extraction within cells.
    - **Skeleton:** Copy the entire ZIP archive. In each translatable XML file, replace paragraph text content with placeholder tokens (`{{SEG:001}}`). Store the modified ZIP as the `.skl` file.
  - `rebuild()`:
    - Open skeleton ZIP.
    - For each translatable XML file, find placeholder tokens and replace with translated content.
    - **Run reconstruction:** Convert target Segment (text + InlineCodes) back into `<w:r>` elements. Each InlineCode open/close boundary creates a new run with the formatting properties from InlineCode.data. Text between codes becomes `<w:t>` content in the run.
    - Preserve `xml:space="preserve"` on `<w:t>` elements to maintain leading/trailing spaces.
    - Write the modified ZIP to the output path.
  - `getSupportedExtensions()`: `['.docx']`.

**Not in this step:**
- No tracked changes (`<w:ins>`, `<w:del>`, `<w:rPrChange>`). Phase 2 extracts the current visible text only. Tracked changes are preserved in the skeleton but not exposed as translatable content. Phase 3 could add revision-aware extraction.
- No content controls / structured document tags (`<w:sdt>`). Preserved in skeleton.
- No embedded Excel charts or SmartArt text. Preserved in skeleton.
- No form fields (`<w:fldSimple>`, `<w:fldChar>`). Preserved in skeleton.
- No text boxes (`<w:txbxContent>` inside `<mc:AlternateContent>`). These use a different extraction path. Phase 3.
- No `.doc` (legacy binary format). Only `.docx` (OOXML).

**External deps:** `ext-zip`, `ext-dom`, `ext-libxml`.

Estimated effort: 4-6 sessions. This is the largest single deliverable in Phase 2. See Deliverable 3 for the detailed design.

---

### Step 4: catframework/qa

**Per-pair quality checks. Small package, high value.**

Features:

- Individual check classes, each implementing `QualityCheckInterface`:

  - `TagConsistencyCheck` (id: `tag_consistency`):
    - Verify source and target have the same InlineCode IDs.
    - Flag: missing codes in target, extra codes in target, mismatched open/close pairs.
    - Severity: ERROR (missing or extra tags usually produce broken output).

  - `NumberConsistencyCheck` (id: `number_mismatch`):
    - Extract numbers from source and target plain text (regex: digits with optional decimal separators, thousands separators, sign).
    - Flag: numbers present in source but missing from target.
    - Language-aware: different locales use different decimal/thousands separators (1,234.56 vs 1.234,56). Use `ext-intl` NumberFormatter for locale-aware parsing.
    - Severity: WARNING (numbers might be intentionally localized).

  - `EmptyTranslationCheck` (id: `empty_translation`):
    - Flag segment pairs where source is non-empty but target is null or empty.
    - Severity: ERROR.

  - `WhitespaceCheck` (id: `whitespace_mismatch`):
    - Compare leading and trailing whitespace between source and target.
    - Flag mismatches.
    - Severity: WARNING.

  - `DoubleSpaceCheck` (id: `double_space`):
    - Flag consecutive spaces in target text.
    - Severity: INFO.

  - `TerminologyConsistencyCheck` (id: `terminology_violation`):
    - If a TerminologyProviderInterface is available, scan target text for forbidden terms and check that required terms are used.
    - Severity: WARNING (forbidden term used) or INFO (approved term not used).
    - This check is optional: it requires a TerminologyProvider to be injected. If none is provided, the check is a no-op.

- `QualityRunner`: convenience class that runs all registered checks against a BilingualDocument.
  - `register(QualityCheckInterface $check): void` — add a check.
  - `run(BilingualDocument $document): array<QualityIssue>` — run all checks on all pairs, return aggregated results.
  - `runOnPair(SegmentPair $pair, string $sourceLang, string $targetLang): array<QualityIssue>` — run all checks on one pair.

**Not in this step:**
- No consistency check across segments (e.g., "same source translated differently in different segments"). This requires cross-segment analysis and is Phase 3.
- No spell-check integration (would require an external spell-check library or API).
- No length restriction checks (target length relative to source). Useful for software localization but not general translation. Phase 3.
- No regex-based custom checks (user-defined patterns). Phase 3 enhancement.

**External deps:** `ext-intl` (for number formatting locale awareness).

Estimated effort: 2 sessions. Individual checks are small and testable. The QualityRunner is trivial.

---

### Step 5: catframework/terminology

**Term recognition and lookup with TBX import.**

Features:

- `TbxParser`: parse TBX v2 (ISO 30042) files.
  - Extract concept entries (`<conceptEntry>`) with language sections (`<langSec>`) and term sections (`<termSec>`).
  - Map to TermEntry model: source term, target term, definition, domain.
  - Handle `<termNote type="administrativeStatus">` for forbidden terms (status = "deprecatedTerm" or "supersededTerm" → `forbidden = true`).
  - Handle `<descrip type="definition">` for definitions.
  - Handle `<descrip type="subjectField">` for domain.

- `SqliteTerminologyProvider`: implements `TerminologyProviderInterface` with SQLite backend.
  - Schema: `term_entries` table with source_term, target_term, source_lang, target_lang, definition, domain, forbidden, source_term_normalized (for matching).
  - `recognize()`:
    - Build a list of all source terms for the language pair.
    - Scan the input text for occurrences of each term.
    - Matching strategy: case-insensitive for Latin scripts (`mb_strtolower`), exact for non-Latin (Devanagari, Arabic) since these scripts have no case distinction.
    - Word-boundary aware: "translate" should not match inside "mistranslate". Use `\b` word boundary in regex, with Unicode-aware mode.
    - Return TermMatch objects with positions.
  - `lookup()`: direct search by source term (normalized). Return all matching TermEntry objects.
  - `import()`: parse TBX file, insert entries. Return count.
  - `addEntry()`: insert single TermEntry.
  - Performance: for termbases up to ~10k entries, loading all source terms into memory and scanning with strpos/preg_match is fast enough. For larger termbases, an Aho-Corasick multi-pattern matcher would be Phase 3.

**Not in this step:**
- No TBX export (TBX is an import format; terminology is managed in the tool's own storage, not round-tripped through TBX).
- No Aho-Corasick or trie-based matching. Brute-force scan is fine for < 10k terms.
- No morphological matching (matching "translated" against the term "translate"). Would require a stemmer/lemmatizer. Phase 3.
- No term auto-insertion (pre-populating target with terminology translations). Phase 3 editor feature.

**External deps:** `ext-pdo`, `ext-pdo_sqlite`, `ext-dom` (TBX parsing), `ext-intl`, `ext-mbstring`.

Estimated effort: 2-3 sessions.

---

## Phase 2 Dependency Graph

```
core (Phase 1, exists)
 ├── tmx (Step 1)
 │    └── translation-memory (Step 2) ──> tmx
 ├── filter-docx (Step 3, independent of TM)
 ├── qa (Step 4, independent)
 └── terminology (Step 5, independent)
```

Steps 1-2 are sequential (TM depends on TMX). Steps 3, 4, 5 are independent of each other and of TM. Recommended build order is as listed (highest value first), but filter-docx could be built before TM if you need DOCX support urgently.

---

## What is explicitly NOT Phase 2

| Feature | Why deferred |
|---|---|
| MT adapters (DeepL, Google) | Requires API keys, HTTP client stack, rate limiting, cost tracking. Low priority while TM and QA cover the core workflow. Phase 3. |
| XLIFF 2.0 | 1.2 works. 2.0's different inline model (`<pc>`, `<sc>`/`<ec>`) requires a mapping layer. Phase 3. |
| filter-xlsx | Same OOXML pattern as DOCX but less frequently translated. Easy to add once DOCX filter is proven. Phase 3. |
| filter-pptx | Same reasoning as XLSX. Phase 3. |
| Reference Laravel app | Depends on stable framework packages. Phase 3. |
| React editor component | Depends on reference app. Phase 3. |
| N-gram TM indexing | Length-based pre-filtering is sufficient for ≤100k TUs. Optimize if profiling shows a bottleneck. Phase 3. |
| Cross-segment QA | Requires iterating all pairs for consistency checks. Phase 3. |

---

## Total Estimated Effort

12-17 sessions at 5-10 hours each = roughly 60-170 hours, or 3-8 months at 5-10 hours/week.

The wide range is driven by filter-docx (4-6 sessions). OOXML has enough edge cases to absorb time. TM is the second time sink (3-4 sessions), mostly in getting fuzzy matching and normalization right.

Realistic estimate if things go reasonably well: 4-5 months.
