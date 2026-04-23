# Phase 3: Scope and Build Order

Phase 2 delivered the core CAT features: translation memory, DOCX support, QA, and terminology. Phase 3 completes the framework package layer by adding machine translation, the remaining OOXML formats, and cross-segment QA. After Phase 3, the full set of framework packages is feature-complete and the reference Laravel application (Phase 4) can begin.

---

## Packages in Phase 3

| # | Package / Change | Priority | Depends on |
|---|---|---|---|
| 1 | `catframework/mt` | High | core |
| 2 | `catframework/filter-xlsx` | High | core |
| 3 | `catframework/filter-pptx` | Medium | core |
| 4 | Extend `catframework/qa` | Medium | core, qa |

---

## Build Order and Features

### Step 1: catframework/mt

**Machine translation adapter framework plus two concrete adapters. The `MachineTranslationInterface` was already designed in Phase 1. This step implements it.**

Features:

- `AbstractMtAdapter`: base class for all adapters.
  - Accepts a PSR-18 `ClientInterface` and a PSR-17 `RequestFactoryInterface` + `StreamFactoryInterface` via constructor injection. The user supplies any PSR-18-compatible HTTP client (e.g., `guzzlehttp/guzzle`, `symfony/http-client`). The adapter does not hard-code an HTTP library.
  - Shared error handling: map HTTP 4xx/5xx to `MtException` with provider ID and status code in message.
  - Shared `InlineCode → placeholder` conversion: before sending to any MT API, convert each InlineCode to an XML placeholder (`<x id="1"/>`, `<x id="2"/>`, etc.). Store the original InlineCode objects keyed by their placeholder ID. After receiving the MT response, restore InlineCodes from the map.

- `DeepLAdapter`: implements `MachineTranslationInterface`.
  - Endpoint: `https://api.deepl.com/v2/translate` (DeepL Pro) and `https://api-free.deepl.com/v2/translate` (DeepL Free). Determined by the API key suffix — free keys end in `:fx`.
  - Parameters: `text[]`, `source_lang`, `target_lang`, `tag_handling=xml`, `ignore_tags=x`.
  - Tag handling: `tag_handling=xml` tells DeepL to treat content as XML and preserve tags. `ignore_tags=x` tells it the `<x/>` placeholders are untranslatable markers. InlineCodes survive translation without being paraphrased.
  - `translateBatch()`: pass all segments as separate `text[]` values in one HTTP request. DeepL returns one translation per input in the same order.
  - Language code mapping: DeepL uses its own codes (EN-US, EN-GB, PT-BR, ZH-HANS). Map from BCP 47 on input. For source lang, strip variant suffixes (DeepL source doesn't accept EN-US, only EN). For target lang, preserve or map variants.
  - `getProviderId()`: returns `"deepl"`.

- `GoogleTranslateAdapter`: implements `MachineTranslationInterface`.
  - Endpoint: Cloud Translation API v3 (`https://translation.googleapis.com/v3/projects/{project}/locations/global:translateText`). Requires API key or service account credentials.
  - Authentication: accept a pre-configured API key string in the constructor. For service account auth, the user creates their own PSR-18 middleware to attach the Authorization header. The adapter does not manage OAuth itself.
  - Tag handling: Google Translate v3 does NOT reliably preserve arbitrary XML tags. Strategy: strip all InlineCodes from the source before sending. Return a Segment with translated plain text and no InlineCodes. The translator must re-apply codes manually in the editor if needed. Document this limitation clearly.
  - `translateBatch()`: Google v3 does not support batching multiple segments in one call. Loop over `translate()` internally. A retry with exponential backoff is included (max 3 retries, 1s/2s/4s delays).
  - `getProviderId()`: returns `"google_v3"`.

- `NullMtAdapter`: implements `MachineTranslationInterface`. Returns an empty Segment for every input. Used in tests and as a safe default when no MT is configured.

**Not in this step:**
- No OAuth flow for Google service accounts. API key only.
- No rate limiting beyond Google's retry logic. DeepL rate limits are permissive for typical translation workloads.
- No cost estimation or quota tracking. Phase 4 (reference app) concern.
- No Microsoft Azure Translator, Amazon Translate, or Yandex adapters. Add as community contributions.
- No context-window MT (sending surrounding segments with the current one for better coherence). DeepL supports `context` param; add in Phase 4 if the reference app benefits from it.

**External deps:** `psr/http-client` (^1.0), `psr/http-factory` (^1.0), `psr/http-message` (^1.1 or ^2.0). All exist on Packagist and are widely supported.

Estimated effort: 2–3 sessions.

---

### Step 2: catframework/filter-xlsx

**Excel (.xlsx) file filter. Same OOXML ZIP structure as DOCX but a fundamentally different text storage model.**

Features:

- `XlsxFilter`: implements `FileFilterInterface`.
  - `supports()`: matches `.xlsx` extension.
  - `extract()`:
    - Open ZIP via `ext-zip`.
    - Parse `xl/sharedStrings.xml`. All unique string values in an XLSX file are stored here as `<si>` (string item) elements, referenced by zero-based index from cells. This is the central challenge: see Deliverable 3 for the full design.
    - Parse `xl/workbook.xml` to get sheet names and IDs.
    - Parse each sheet (`xl/worksheets/sheet{N}.xml`). For each cell (`<c>`):
      - If `t="s"` (shared string): the cell value is a shared string index. Collect the index.
      - If `t="inlineStr"`: the string is embedded directly. Extract from `<is><t>`.
      - If `t="str"` (formula result): extract the cached value but mark as non-translatable (formula cells).
      - Numeric, boolean, error cells: skip.
    - **Rich text in shared strings:** An `<si>` element may contain `<r>` sub-elements (runs with formatting), not just a plain `<t>`. Map runs to InlineCode pairs the same way DOCX runs are handled (formatting change = new InlineCode).
    - **Deduplication:** Multiple cells may reference the same shared string index. Each unique `<si>` index produces exactly one SegmentPair. Track which cells reference which index.
    - **Skeleton:** Copy the ZIP. In the skeleton's `sharedStrings.xml`, replace the text content of each translatable `<si>` with a placeholder token (`{{SEG:001}}`). In each `worksheet`, cell references to translated shared strings are left pointing at the same index (the skeleton's sharedStrings will be updated on rebuild). Non-translatable cells are untouched.
  - `rebuild()`:
    - Open skeleton ZIP.
    - In `sharedStrings.xml`, for each placeholder token, replace with the translated target text (or source text if no translation was provided). Reconstruct `<r>` elements from target Segment InlineCodes.
    - Write the modified ZIP to the output path.
  - `getSupportedExtensions()`: `['.xlsx']`.

**Not in this step:**
- No `.xls` (legacy binary format). XLSX only.
- No `.xlsm` (macro-enabled). XLSX only. Macros are unrelated to text.
- No chart series labels or axis titles. Preserved in skeleton.
- No header/footer content (`<oddHeader>`, `<oddFooter>` in sheet XML). Preserved in skeleton. Add in a future pass if needed.
- No pivot table captions.
- No `calcChain.xml` handling. Delete `xl/calcChain.xml` from the rebuilt ZIP — Excel will regenerate it. This is the standard approach when modifying cell content without re-evaluating formulas.

**External deps:** `ext-zip`, `ext-dom`, `ext-libxml`.

Estimated effort: 3–4 sessions. Shared strings deduplication and the rich text run mapping are the time sinks.

---

### Step 3: catframework/filter-pptx

**PowerPoint (.pptx) file filter. Same OOXML ZIP structure, uses DrawingML namespace instead of WordprocessingML.**

Features:

- `PptxFilter`: implements `FileFilterInterface`.
  - `supports()`: matches `.pptx` extension.
  - `extract()`:
    - Open ZIP via `ext-zip`.
    - Parse `ppt/presentation.xml` to get slide IDs and order.
    - For each slide (`ppt/slides/slide{N}.xml`):
      - Walk shape tree (`<p:spTree>`) for text-containing shapes (`<p:sp>`, `<p:pic>` captions).
      - Each shape has a text body (`<p:txBody>`) with paragraphs (`<a:p>`) and runs (`<a:r>`).
      - Run structure mirrors DOCX: `<a:r>` has `<a:rPr>` (run properties) and `<a:t>` (text). Apply the same run merging and InlineCode generation logic used in DocxFilter. Formatting changes between merged run groups → InlineCode pairs.
      - Each `<a:p>` paragraph = one SegmentPair.
      - Empty paragraphs (visual spacing): skip (no translatable content).
    - For each notes slide (`ppt/notesSlides/notesSlide{N}.xml`):
      - Extract text from note shapes using the same paragraph/run logic.
      - Tag each SegmentPair's context with `source: "notes"` in metadata so the editor can display slide notes separately.
    - **Skeleton:** Same string-replacement strategy as DOCX. Replace `<a:t>` content within relevant shapes with placeholder tokens. Preserve all shape geometry, styling, and non-text content.
  - `rebuild()`:
    - Open skeleton ZIP.
    - Replace placeholder tokens with translated content. Reconstruct `<a:r>` elements from target InlineCodes.
    - Write modified ZIP to output path.
  - `getSupportedExtensions()`: `['.pptx']`.

**Not in this step:**
- No slide masters (`ppt/slideMasters/`). These are templates, not translatable content for a specific presentation.
- No slide layouts (`ppt/slideLayouts/`). Same reasoning.
- No hidden slides. Extract only slides where `show` attribute is not `false`.
- No SmartArt text (`<p:graphicFrame>` with SmartArt). Complex to handle; rare in practice.
- No embedded Excel chart data labels. Preserved in skeleton.
- No `.ppt` (legacy binary format).

**External deps:** `ext-zip`, `ext-dom`, `ext-libxml`.

Estimated effort: 2–3 sessions. Heavily leverages the patterns already established by DocxFilter.

---

### Step 4: Cross-segment consistency check (extend catframework/qa)

**New check class added to the existing `catframework/qa` package. Detects inconsistent translations of the same source segment.**

This requires a new method on `QualityRunner` because the existing `QualityCheckInterface::check()` operates on a single `SegmentPair` and has no visibility into the rest of the document.

Changes:

- New interface `DocumentQualityCheckInterface`:
  ```php
  interface DocumentQualityCheckInterface
  {
      public function checkDocument(
          BilingualDocument $document,
          string $sourceLanguage,
          string $targetLanguage,
      ): array; // QualityIssue[]
  }
  ```
  This interface is separate from `QualityCheckInterface` so existing per-pair checks are not affected.

- `QualityRunner::runOnDocument(BilingualDocument $document): array` — runs all registered `DocumentQualityCheckInterface` checks on the full document.

- `SegmentConsistencyCheck`: implements `DocumentQualityCheckInterface`.
  - Build a map of `normalizedSourceText → [targetTexts]` by iterating all pairs.
  - Flag any source text that maps to 2 or more distinct target texts.
  - Severity: WARNING (inconsistency may be intentional for context-dependent terms).
  - Report issue on every pair involved in the inconsistency, not just the first occurrence.

**Not in this step:**
- No spell-check integration.
- No target length restriction checks (for software localization).
- No regex-based custom check patterns.
- No cross-document consistency (across multiple project files).

**External deps:** none beyond existing `catframework/core`.

Estimated effort: 1 session.

---

## Phase 3 Dependency Graph

```
core (exists)
 ├── mt (Step 1, standalone — only psr/* interfaces)
 ├── filter-xlsx (Step 2, independent)
 ├── filter-pptx (Step 3, independent)
 └── qa (exists — extended in Step 4)
```

All four steps are independent of each other. Recommended order: MT first (highest translator value), then filter-xlsx, filter-pptx, QA extension last (smallest effort, good session to close Phase 3).

---

## What is explicitly NOT Phase 3

| Feature | Why deferred |
|---|---|
| Reference Laravel app | Depends on all framework packages being stable. Phase 4. |
| React editor component | Depends on reference app. Phase 4. |
| XLIFF 2.0 | XLIFF 1.2 works. 2.0 requires a new inline element mapping (`<pc>`, `<sc>`/`<ec>`). Add when a user requests it. |
| N-gram TM indexing | Only needed if TM performance is a bottleneck with > 100k entries. Revisit when profiling shows the problem. |
| Morphological term matching | Requires stemmer/lemmatizer. No suitable PHP library exists on Packagist for Hindi/Urdu. Deferred indefinitely unless a solution is found. |
| Microsoft Azure Translator adapter | Same PSR-18 pattern as DeepL/Google. Easy to add; not a priority for a solo developer. Community contribution. |
| filter-odt (LibreOffice) | ODT is an ODF zip with flat XML. Lower priority than OOXML formats since most professional translation work uses Microsoft Office formats. |
| ICU-based segmentation engine | `IntlBreakIterator` implementation of `SegmentationEngineInterface`. Deferred per D4. Revisit if SRX proves inadequate for a specific language. |
| Context-aware MT (`context` param) | DeepL supports sending surrounding segments as context. Useful in the reference app editor. Phase 4. |

---

## Total Estimated Effort

8–11 sessions at 5–10 hours each = roughly 40–110 hours, or 2–4 months at 5–10 hours/week.

MT adapter is the most valuable and shortest (2–3 sessions). XLSX is the most complex of the three new packages (3–4 sessions). PPTX leverages DOCX patterns heavily (2–3 sessions). The QA extension is a single focused session.
