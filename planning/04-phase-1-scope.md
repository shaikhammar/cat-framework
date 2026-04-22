# Phase 1 Scope

Goal: the smallest working vertical slice that proves the architecture.

**File in → paragraphs extracted → sentences segmented → translations entered → bilingual document saved → file rebuilt with translations.**

---

## Packages in Phase 1

Five packages (plus srx as a sub-deliverable of segmentation):

1. `catframework/core`
2. `catframework/filter-plaintext`
3. `catframework/filter-html`
4. `catframework/segmentation` (includes `catframework/srx`)
5. `catframework/xliff`

---

## Build Order and Features

### Step 1: catframework/core

**Build first. Everything depends on this.**

Features:

- All 7 data models: InlineCode, Segment, SegmentPair, BilingualDocument, TranslationUnit, MatchResult, QualityIssue. Yes, all seven, even though Phase 1 doesn't use TranslationUnit/MatchResult/QualityIssue yet. They are trivial readonly classes and having them in core from the start means Phase 2 packages won't require a core breaking change.
- All 6 contract interfaces: FileFilterInterface, SegmentationEngineInterface, TranslationMemoryInterface, TerminologyProviderInterface, QualityCheckInterface, MachineTranslationInterface. Same reasoning: define them now, implement later.
- Supporting enums: InlineCodeType, SegmentState, MatchType, QualitySeverity.
- Supporting types: TermEntry, TermMatch.
- Exception classes: FilterException, SegmentationException, TmException, TerminologyException, MtException.
- PHPUnit test suite for data model behavior (Segment::getPlainText, Segment::isEmpty, etc.).

**Not in this step:** No implementations of any interface. Core is models + contracts only.

Estimated effort: 1-2 sessions (5-10 hours).

---

### Step 2: catframework/filter-plaintext

**First filter. Proves the extract/rebuild pipeline with zero InlineCode complexity.**

Features:

- `supports()`: matches `.txt` extension.
- `extract()`: reads file as UTF-8, splits on double-newline (`\n\n`) into paragraphs. Each paragraph becomes one SegmentPair (source = paragraph text, target = null). Skeleton stores the whitespace structure between paragraphs (so rebuild preserves exact original spacing, including trailing newlines).
- `rebuild()`: reassembles the file from skeleton + translated segments. Untranslated segments use source text as fallback.
- `getSupportedExtensions()`: `['.txt']`.
- Encoding detection via `mb_detect_encoding()` with conversion to UTF-8 internal. If detection fails, assume UTF-8.

**Not in this step:** No InlineCode (plain text has no formatting). No handling of BOM markers, mixed line endings, or binary-safe detection. These are straightforward additions later.

Estimated effort: 1 session.

Milestone: after this step, you can run:
```
filter-plaintext extract input.txt → BilingualDocument → manually set targets → filter-plaintext rebuild → output.txt
```

---

### Step 3: catframework/filter-html

**Second filter. Introduces InlineCode handling, which is the core complexity of CAT file filters.**

Features:

- `supports()`: matches `.html`, `.htm` extensions.
- `extract()`:
  - Parse HTML with `ext-dom` (DOMDocument::loadHTML with libxml error suppression for messy real-world HTML).
  - Walk the DOM tree. Block elements define segment boundaries: `p`, `div`, `h1`-`h6`, `li`, `td`, `th`, `dt`, `dd`, `blockquote`, `figcaption`, `caption`.
  - Inline elements become InlineCodes within a segment: `b`, `strong`, `i`, `em`, `a`, `span`, `sub`, `sup`, `code`, `abbr`, `u`, `small`, `mark`.
  - Each block element's content becomes one SegmentPair. Source Segment contains text interleaved with InlineCodes.
  - Skeleton = original HTML with translatable text replaced by placeholder tokens (e.g., `{{SEG:001}}`).
- `rebuild()`: parse skeleton HTML, replace placeholder tokens with target Segment content (InlineCodes converted back to HTML tags), write output.
- `getSupportedExtensions()`: `['.html', '.htm']`.

**Not in this step:**
- No `<script>`, `<style>`, or `<noscript>` content extraction (these are code, not translatable text).
- No `alt`, `title`, `placeholder`, or `aria-label` attribute extraction. These are translatable but add significant complexity to the skeleton approach. Phase 2.
- No embedded JSON-LD or microdata extraction.
- No `<meta>` content extraction (description, keywords).

Estimated effort: 2-3 sessions. This is the hardest filter in Phase 1 because of the inline/block element distinction and InlineCode generation.

Milestone: after this step, you can extract a real HTML page, see paragraphs with inline formatting preserved as codes, translate them, and rebuild a valid translated HTML file.

---

### Step 4: catframework/srx + catframework/segmentation

**Adds sentence-level segmentation. Until now, each paragraph was one segment. Now paragraphs get split into sentences.**

#### catframework/srx

Features:

- Parse SRX 2.0 XML files using `ext-dom`.
- Data structures: `SegmentationRule` (break/no-break flag + before-break regex + after-break regex), `LanguageRule` (language pattern + ordered list of rules), `SegmentationRuleSet` (collection of language rules).
- Language map resolution: given a BCP 47 code like "hi-IN", match against SRX language patterns using regex (e.g., `hi.*` or `.*`).
- Ship a default SRX file bundled with the package. Rules for: English, Hindi, Urdu, Arabic, French, German, Spanish, Chinese, Japanese. Based on publicly available Unicode CLDR/ICU segmentation data (not copied from any proprietary tool).

#### catframework/segmentation

Features:

- `loadRules()`: parse SRX file via the srx package, store rules in memory.
- `segment()`:
  - Extract plain text from input Segment.
  - Apply SRX rules: iterate through text, test each position against break/no-break regexes for the given language.
  - Split at break positions.
  - Distribute InlineCodes from the original Segment to the resulting sub-Segments based on character positions.
  - Handle codes that span a split boundary: close the tag at the end of segment N (insert a synthetic CLOSING InlineCode), re-open at the start of segment N+1 (insert a synthetic OPENING InlineCode with the same ID). This mirrors XLIFF segmentation behavior.
- Regex engine: use `preg_match` with the `u` (UTF-8) flag. For rules that need Unicode character properties (e.g., `\p{Lu}` for uppercase), `ext-intl` is available but `preg` with `u` flag should suffice for Phase 1.

**Not in this step:**
- No custom abbreviation dictionaries beyond what's encoded in SRX rules.
- No "merge segments" operation (joining two segments back into one). Useful in editors but not needed for the pipeline.
- No subsegmentation (splitting within a sentence for alignment purposes).

Estimated effort: 2-3 sessions. The SRX parser is straightforward. The segmentation logic is moderate. The InlineCode spanning is the tricky part (see Deliverable 5).

Milestone: after this step, the pipeline is: HTML → paragraphs → sentences → translate → rebuild HTML. This is a real CAT workflow.

---

### Step 5: catframework/xliff

**Serialization layer. Without this, BilingualDocument only exists in memory. XLIFF lets you save work, resume later, and exchange with other CAT tools.**

Features:

- Write: `BilingualDocument → XLIFF 1.2 file`.
  - Map BilingualDocument metadata to XLIFF `<file>` attributes (original, source-language, target-language, datatype).
  - Map each SegmentPair to a `<trans-unit>` with `<source>` and `<target>`.
  - Map InlineCodes to XLIFF 1.2 inline elements: OPENING → `<bpt>`, CLOSING → `<ept>`, STANDALONE → `<ph>`.
  - Map SegmentState to XLIFF state attribute (new, translated, reviewed, final).
  - Store lock status via `translate="no"` attribute.
  - Store skeleton as a `<skeleton>` element or external file reference.
- Read: `XLIFF 1.2 file → BilingualDocument`.
  - Reverse of the above. Parse XLIFF, reconstruct BilingualDocument with SegmentPairs, InlineCodes, and skeleton.
  - Tolerate missing optional elements (no target = untranslated pair).
- Validate: basic structural validation (required elements present, language codes set). Not a full XLIFF schema validator.

**Not in this step:**
- No XLIFF 2.0 support. XLIFF 1.2 is more widely supported by existing CAT tools (Trados, memoQ, Wordfast all handle 1.2 reliably). XLIFF 2.0 uses a significantly different inline code model (`<pc>`, `<ph>`, `<sc>`/`<ec>` instead of `<bpt>`/`<ept>`/`<ph>`), so supporting both requires an abstraction layer. Phase 2.
- No XLIFF `<alt-trans>` elements (alternative translations, used for TM matches). Phase 2 with TM.
- No `<note>` or `<context-group>` handling beyond passthrough.

Estimated effort: 2-3 sessions. The mapping between core models and XLIFF elements is well-defined. The main complexity is inline code serialization.

Milestone: after this step, the full Phase 1 pipeline is complete. You can extract an HTML file, segment it, save it as XLIFF, close the application, reopen the XLIFF, continue translating, and rebuild the HTML.

---

## What is explicitly NOT in Phase 1 (and why)

| Feature/Package | Why not yet |
|---|---|
| Translation Memory | Requires fuzzy matching algorithm, storage engine, and TMX parser. Each is substantial. The pipeline works without TM (translator types translations manually). Phase 2 priority #1. |
| Fuzzy matching | Depends on TM. Also a hard algorithmic problem (UTF-8 safe edit distance with inline code awareness). Phase 2. |
| TMX parser | Only needed by TM. No standalone use case in Phase 1. Ships with TM in Phase 2. |
| Terminology | Useful but not critical path. Translator can reference glossaries manually. Phase 2. |
| QA checks | Valuable but not needed for the basic pipeline. A translator can visually check their work. Phase 2. |
| Machine Translation | Requires API keys, HTTP client, rate limiting, cost tracking. Tangential to the core pipeline. Phase 2. |
| filter-docx | The most valuable filter for real work, but also the most complex (OOXML ZIP structure, shared styles, nested runs, embedded objects, headers/footers/footnotes). Build it after the filter pipeline is proven with HTML. Phase 2 priority #1 alongside TM. |
| filter-xlsx | Less frequently translated than DOCX. Phase 2 or 3. |
| filter-pptx | Less frequently translated than DOCX. Phase 2 or 3. |
| XLIFF 2.0 | Different inline model. 1.2 is sufficient and more compatible. Phase 2. |
| Reference Laravel app | No point building the app until the framework packages are stable. Phase 3. |
| Editor component (React) | Depends on the reference app. Phase 3. |

---

## Phase 1 Dependency Graph

```
core (Step 1)
 ├── filter-plaintext (Step 2)
 ├── filter-html (Step 3)
 ├── srx ──→ segmentation (Step 4)
 └── xliff (Step 5)
```

Steps 2-5 all depend on core but not on each other. The numbered order is the recommended build sequence (simplest first, adds complexity incrementally), but steps could be reordered if needed.

---

## Total Estimated Effort

8-12 sessions at 5-10 hours each = roughly 40-120 hours, or 2-6 months at the stated pace. The wide range reflects uncertainty in the segmentation and InlineCode spanning work, which is the least predictable part.

The honest answer: if segmentation + InlineCode spanning goes smoothly, 2-3 months. If it turns into a rabbit hole (see Deliverable 5, Risk #1), closer to 5-6 months.
