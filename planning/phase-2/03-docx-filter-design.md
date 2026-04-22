# DOCX Filter Design

The most complex filter in the framework. This document covers the OOXML structure, extraction strategy, InlineCode generation from runs, skeleton strategy, and known edge cases.

---

## OOXML structure of a DOCX file

A `.docx` file is a ZIP archive containing XML files:

```
my-document.docx (ZIP)
├── [Content_Types].xml          ← MIME mappings
├── _rels/
│   └── .rels                    ← root relationships
├── word/
│   ├── document.xml             ← main body (primary extraction target)
│   ├── styles.xml               ← style definitions
│   ├── settings.xml             ← document settings
│   ├── fontTable.xml            ← font declarations
│   ├── numbering.xml            ← list numbering definitions
│   ├── header1.xml              ← header (one per section, translatable)
│   ├── header2.xml
│   ├── footer1.xml              ← footer (translatable)
│   ├── footnotes.xml            ← footnotes (translatable)
│   ├── endnotes.xml             ← endnotes (translatable)
│   ├── comments.xml             ← comments (NOT translated in Phase 2)
│   ├── media/
│   │   ├── image1.png           ← embedded images (preserved, not translated)
│   │   └── image2.jpg
│   └── _rels/
│       └── document.xml.rels    ← relationships (hyperlink targets, image refs)
└── docProps/
    ├── core.xml                 ← document metadata
    └── app.xml                  ← application metadata
```

**Translatable XML files:** `document.xml`, `header{N}.xml`, `footer{N}.xml`, `footnotes.xml`, `endnotes.xml`.

**Not translated:** `styles.xml`, `settings.xml`, `fontTable.xml`, `numbering.xml`, `comments.xml`, `media/*`, `docProps/*`. All preserved exactly in the skeleton.

---

## OOXML text structure

### Paragraphs, runs, and text

```xml
<w:p>                                    <!-- paragraph -->
  <w:pPr>                               <!-- paragraph properties (alignment, style, etc.) -->
    <w:pStyle w:val="Heading1"/>
  </w:pPr>
  <w:r>                                  <!-- run 1 -->
    <w:rPr>                              <!-- run properties (bold, italic, font, etc.) -->
      <w:b/>                             <!-- bold -->
    </w:rPr>
    <w:t>Important:</w:t>               <!-- text content -->
  </w:r>
  <w:r>                                  <!-- run 2 -->
    <w:t xml:space="preserve"> Click the button.</w:t>
  </w:r>
</w:p>
```

This renders as: "**Important:** Click the button."

Key points:
- `<w:p>` = paragraph (our segment boundary).
- `<w:r>` = run (a contiguous span of text with uniform formatting).
- `<w:rPr>` = run properties (formatting applied to this run).
- `<w:t>` = text content. `xml:space="preserve"` preserves leading/trailing spaces.
- `<w:pPr>` = paragraph properties (not translated, but preserved in skeleton).

### The run fragmentation problem

Word frequently splits text across multiple runs for no visible reason:

```xml
<w:p>
  <w:r><w:t>Hel</w:t></w:r>
  <w:r><w:t>lo wor</w:t></w:r>
  <w:r><w:t>ld</w:t></w:r>
</w:p>
```

All three runs have no `<w:rPr>` (same default formatting), but Word split "Hello world" across three runs. This happens due to: spell-check boundaries, revision history cleanup, copy-paste artifacts, and field code insertion/deletion.

If we treat each run as a separate text unit, we get three segments for one paragraph, all incomplete. **Run merging** is essential.

---

## Extraction algorithm

### Step 1: Open the ZIP

```php
$zip = new ZipArchive();
$zip->open($filePath, ZipArchive::RDONLY);
```

### Step 2: Identify translatable files

Read `[Content_Types].xml` and `word/_rels/document.xml.rels` to find all parts. Or use a hardcoded list of known translatable paths (simpler, sufficient for Phase 2):

```php
$translatableFiles = ['word/document.xml'];

// Probe for optional files
foreach (['word/header', 'word/footer'] as $prefix) {
    for ($i = 1; $i <= 10; $i++) {
        $path = "{$prefix}{$i}.xml";
        if ($zip->locateName($path) !== false) {
            $translatableFiles[] = $path;
        }
    }
}
foreach (['word/footnotes.xml', 'word/endnotes.xml'] as $path) {
    if ($zip->locateName($path) !== false) {
        $translatableFiles[] = $path;
    }
}
```

### Step 3: Extract paragraphs from each file

For each translatable XML file:

1. Load XML: `DOMDocument::loadXML($zip->getFromName($path))`.
2. Register namespace: `w` → `http://schemas.openxmlformats.org/wordprocessingml/2006/main`.
3. Find all `<w:p>` elements via XPath: `//w:p`.
4. For each paragraph, extract content (see "Run merging and InlineCode generation" below).
5. Create a SegmentPair. Store the XML file path and paragraph index in `SegmentPair::$context` for rebuild.

### Step 4: Run merging and InlineCode generation

This is the core extraction logic. For each `<w:p>`:

```
Input: a <w:p> DOM element with child <w:r> elements.
Output: a Segment with interleaved text strings and InlineCodes.

Algorithm:

1. Collect all runs in the paragraph. For each run, extract:
   - rPr: the <w:rPr> element as serialized XML string (null if absent).
   - text: concatenated text content of all <w:t> children.
   - Special content: <w:br/> (line break), <w:tab/> (tab), <w:sym/> (symbol).

2. Merge adjacent runs with identical rPr:
   - Compare rPr XML strings. If identical (or both null), merge text.
   - Result: a list of MergedRun objects: { rPr: ?string, text: string }.

3. Determine the "base" formatting (the most common rPr in the paragraph,
   or null if most text has no formatting). Text with base formatting
   becomes plain text in the Segment. Text with different formatting
   gets wrapped in InlineCode pairs.

4. Build the Segment elements array:
   - For each MergedRun:
     - If rPr matches base: append text string to elements.
     - If rPr differs from base:
       a. Create InlineCode(id=N, type=OPENING, data=rPr_xml).
       b. Append to elements.
       c. Append text string.
       d. Create InlineCode(id=N, type=CLOSING, data=rPr_xml).
       e. Append to elements.
       f. Increment N.

5. Return the Segment.
```

### Example

Input OOXML:
```xml
<w:p>
  <w:r><w:t>Click the </w:t></w:r>
  <w:r><w:rPr><w:b/></w:rPr><w:t>Save</w:t></w:r>
  <w:r><w:t xml:space="preserve"> button to save your </w:t></w:r>
  <w:r><w:rPr><w:i/></w:rPr><w:t>changes</w:t></w:r>
  <w:r><w:t>.</w:t></w:r>
</w:p>
```

After run merging (no adjacent same-format runs here, so no merges):

| # | rPr | Text |
|---|---|---|
| 1 | null (base) | "Click the " |
| 2 | `<w:b/>` | "Save" |
| 3 | null (base) | " button to save your " |
| 4 | `<w:i/>` | "changes" |
| 5 | null (base) | "." |

Base formatting = null (most runs have no rPr).

Output Segment elements:
```
[
  "Click the ",
  InlineCode(id="1", OPENING, data="<w:rPr><w:b/></w:rPr>"),
  "Save",
  InlineCode(id="1", CLOSING, data="<w:rPr><w:b/></w:rPr>"),
  " button to save your ",
  InlineCode(id="2", OPENING, data="<w:rPr><w:i/></w:rPr>"),
  "changes",
  InlineCode(id="2", CLOSING, data="<w:rPr><w:i/></w:rPr>"),
  ".",
]
```

Plain text: `Click the Save button to save your changes.`
Display: `Click the {1}Save{/1} button to save your {2}changes{/2}.`

---

## Skeleton strategy

### Creating the skeleton

1. Copy the entire ZIP to the skeleton file path (`.xlf.skl`).
2. For each translatable XML file inside the skeleton ZIP:
   a. Load the XML.
   b. For each extracted `<w:p>`, replace ALL child `<w:r>` elements with a single placeholder run:
      ```xml
      <w:r><w:t>{{SEG:001}}</w:t></w:r>
      ```
   c. Preserve `<w:pPr>` (paragraph properties) untouched.
   d. Save the modified XML back into the skeleton ZIP.

The skeleton ZIP is now a complete DOCX file where every translatable paragraph contains a single placeholder run. All non-translatable content (images, styles, headers structure, etc.) is preserved exactly.

### Why replace all runs with one placeholder run (not token-per-run)

Each paragraph maps to one SegmentPair. The segment's InlineCodes carry enough information to reconstruct the run structure. Replacing all runs with a single placeholder keeps the skeleton simple: one token per paragraph. During rebuild, the single placeholder is replaced with a set of runs reconstructed from the target Segment.

### Skeleton file size

The skeleton is a copy of the original DOCX. For a text-heavy 50-page document (~500KB), the skeleton is ~500KB (text replaced by tokens is smaller, so slightly less). For a document with many images, the skeleton could be several MB. This is larger than HTML skeletons but manageable. The external skeleton file approach (Decision D2) handles this correctly.

---

## Rebuild algorithm

### Step 1: Open the skeleton ZIP

Copy the skeleton to the output path, then open for modification:

```php
copy($skeletonPath, $outputPath);
$zip = new ZipArchive();
$zip->open($outputPath);
```

### Step 2: Replace placeholders

For each translatable XML file:

1. Load XML from the ZIP.
2. Find placeholder runs (`<w:r>` containing `{{SEG:NNN}}`).
3. For each placeholder, look up the corresponding SegmentPair.
4. Convert the target Segment (or source if untranslated) into `<w:r>` elements:

```
Segment elements: ["Click the ", InlineCode(OPENING, data=bold_rPr), "Save", InlineCode(CLOSING), " button."]

Output runs:
<w:r><w:t xml:space="preserve">Click the </w:t></w:r>
<w:r><w:rPr><w:b/></w:rPr><w:t>Save</w:t></w:r>
<w:r><w:t xml:space="preserve"> button.</w:t></w:r>
```

Algorithm:
- Walk the Segment elements.
- Accumulate text until an InlineCode OPENING is hit.
- Flush accumulated text as a run with base formatting (or current formatting context).
- On OPENING: push the InlineCode's `data` (rPr XML) as the current formatting.
- Continue accumulating text under this formatting.
- On CLOSING: flush accumulated text as a run with the current formatting, pop formatting.
- After all elements processed: flush any remaining text.
- Each flush creates one `<w:r>` with the appropriate `<w:rPr>` and `<w:t>`.

5. Replace the placeholder `<w:r>` element in the DOM with the generated runs.
6. Save modified XML back into the ZIP.

### Step 3: Close and write

```php
$zip->close(); // writes the modified ZIP to $outputPath
```

---

## Hyperlink handling

OOXML hyperlinks:

```xml
<w:hyperlink r:id="rId5">
  <w:r>
    <w:rPr><w:rStyle w:val="Hyperlink"/></w:rPr>
    <w:t>Click here for details</w:t>
  </w:r>
</w:hyperlink>
```

The `r:id="rId5"` references an entry in `word/_rels/document.xml.rels`:
```xml
<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink"
    Target="https://example.com" TargetMode="External"/>
```

**Extraction strategy:**

- Treat `<w:hyperlink>` as an inline element (like `<a>` in HTML).
- Create an InlineCode OPENING with `data` containing the hyperlink element's attributes (including `r:id`) and the relationship target URL.
- Extract the display text from child runs (with their own InlineCode pairs if formatted).
- Create an InlineCode CLOSING.
- The translator can change the display text ("Click here for details" → "Cliquez ici pour plus de détails") but the URL is preserved in the InlineCode data.

**Rebuild:** Reconstruct the `<w:hyperlink>` wrapper around the translated runs. Preserve the `r:id` reference.

---

## Special inline elements

| OOXML element | Meaning | Handling |
|---|---|---|
| `<w:br/>` | Line break | InlineCode STANDALONE. Display: `↵`. |
| `<w:br w:type="page"/>` | Page break | InlineCode STANDALONE. Display: `[page break]`. |
| `<w:tab/>` | Tab character | InlineCode STANDALONE. Display: `→`. |
| `<w:sym w:font="..." w:char="..."/>` | Symbol | InlineCode STANDALONE. Display: the symbol character. |
| `<w:footnoteReference w:id="N"/>` | Footnote ref | InlineCode STANDALONE. Display: `[fn N]`. Not editable. |
| `<w:endnoteReference w:id="N"/>` | Endnote ref | InlineCode STANDALONE. Display: `[en N]`. Not editable. |
| `<w:drawing>` | Image/shape | Skip (not translatable). Preserved in skeleton. |
| `<w:pict>` | Legacy image | Skip. Preserved in skeleton. |

---

## Edge cases and how to handle them

### Empty paragraphs

A `<w:p>` with no `<w:r>` children (or runs with no `<w:t>` content). Common: blank lines, spacing paragraphs.

**Handling:** Skip. Do not create a SegmentPair. The empty paragraph is preserved in the skeleton.

### Paragraphs with only non-translatable content

A paragraph containing only images, form fields, or other non-text elements.

**Handling:** Skip. Preserved in skeleton.

### Split words across runs (run fragmentation)

Covered above in "run merging." Adjacent runs with identical `<w:rPr>` (compared by serialized XML) are merged before InlineCode generation.

### Nested formatting

Bold + italic on the same text:

```xml
<w:r><w:rPr><w:b/><w:i/></w:rPr><w:t>bold italic</w:t></w:r>
```

This is a single run with combined formatting. It produces a single InlineCode pair whose `data` is `<w:rPr><w:b/><w:i/></w:rPr>`. No nesting issue at the run level, because OOXML doesn't nest runs. Each run has a flat set of properties.

### Tracked changes

```xml
<w:ins w:author="John" w:date="2026-01-15T10:00:00Z">
  <w:r><w:t>inserted text</w:t></w:r>
</w:ins>
<w:del w:author="John" w:date="2026-01-15T10:00:00Z">
  <w:r><w:delText>deleted text</w:delText></w:r>
</w:del>
```

**Phase 2 handling:** Extract the current visible text only. This means:
- Text inside `<w:ins>` IS extracted (it's visible in the current document).
- Text inside `<w:del>` is NOT extracted (it's struck-through/hidden).
- `<w:rPrChange>` (formatting changes) is ignored; use the current formatting.

The `<w:ins>` and `<w:del>` wrapper elements are NOT preserved in the translated output. During rebuild, the paragraph is reconstructed with plain runs (no tracked change markup). This means accepting changes in the translated file. This is standard CAT tool behavior (Trados and memoQ both strip tracked changes during translation).

### Table structure

```xml
<w:tbl>
  <w:tr>          <!-- table row -->
    <w:tc>        <!-- table cell -->
      <w:p>...</w:p>   <!-- paragraphs inside cell -->
      <w:p>...</w:p>
    </w:tc>
    <w:tc>
      <w:p>...</w:p>
    </w:tc>
  </w:tr>
</w:tbl>
```

**Handling:** Standard paragraph extraction. The XPath `//w:p` finds paragraphs inside table cells automatically. The table structure (`<w:tbl>`, `<w:tr>`, `<w:tc>`) is preserved in the skeleton. Each cell's paragraphs become separate SegmentPairs.

### Headers and footers

Separate XML files (`word/header1.xml`, etc.) with the same paragraph structure as `document.xml`.

**Handling:** Extract from each header/footer file in the same way. SegmentPair context stores which file the paragraph came from, so rebuild writes back to the correct file.

### Footnotes and endnotes

`word/footnotes.xml` contains `<w:footnote>` elements, each containing `<w:p>` paragraphs. Same structure.

**Handling:** Extract paragraphs from footnotes/endnotes. Skip the auto-generated footnote separator paragraphs (they have `w:type="separator"` or `w:type="continuationSeparator"`).

### Right-to-left paragraphs

For Urdu/Arabic translations, the paragraph direction is controlled by `<w:pPr><w:bidi/></w:pPr>` and run direction by `<w:rPr><w:rtl/></w:rPr>`.

**Handling:** The filter doesn't need to interpret directionality during extraction (text is stored in logical order regardless). During rebuild, if the target language is RTL, the filter should add `<w:bidi/>` to paragraph properties and `<w:rtl/>` to run properties. This requires knowing the target language, which is available from `BilingualDocument::$targetLanguage`.

**Decision:** Add `<w:bidi/>` and `<w:rtl/>` to all paragraphs/runs in the target document when `$targetLanguage` starts with `ar`, `he`, `fa`, `ur`, or other RTL language codes. This is a simple heuristic. A more nuanced approach (per-paragraph direction detection) can be added later.

---

## rPr comparison for run merging

Two runs should be merged if their formatting is identical. The comparison needs to be reliable:

### Approach: canonical XML serialization

1. Serialize `<w:rPr>` to XML string using `DOMDocument::saveXML($rPrNode)`.
2. Problem: attribute order in XML is undefined. `<w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>` and `<w:rFonts w:hAnsi="Arial" w:ascii="Arial"/>` are semantically identical but string-compare as different.

### Solution: normalize before comparison

1. Extract `<w:rPr>` children, sort by tag name.
2. For each child element, sort attributes by name.
3. Serialize the normalized structure.
4. Compare normalized strings.

This is overkill for most documents (Word usually serializes attributes in consistent order), but it prevents false split-refusal on edge cases. If profiling shows it's slow, fall back to direct string comparison with a "try merge, verify on rebuild" strategy.

### Handling inherited styles

A run might have no explicit `<w:rPr>` but inherit formatting from its paragraph style or document defaults. For Phase 2, we do NOT resolve style inheritance. Two runs are "same formatting" only if their explicit `<w:rPr>` elements are identical (or both absent).

This means some theoretically-mergeable runs won't be merged (a run with explicit `<w:b/>` won't merge with a run that's bold via style inheritance). This is conservative and safe: it may produce extra InlineCodes but never produces incorrect merges.

---

## Testing strategy

### Round-trip tests (per Decision D8)

For each test case:
1. Create a test DOCX with known content (using python-docx or a hand-crafted ZIP, stored as test fixtures).
2. Extract → BilingualDocument.
3. Set target segments to known translations.
4. Rebuild → output DOCX.
5. Extract the output DOCX again.
6. Verify: source segments from step 2 match target segments from step 5.
7. Open output DOCX in LibreOffice (CI-friendly) and verify it renders without corruption.

### Test fixtures needed

| # | Test case | What it tests |
|---|---|---|
| 1 | Plain paragraphs, no formatting | Basic extraction and rebuild |
| 2 | Bold, italic, underline runs | InlineCode generation and run reconstruction |
| 3 | Adjacent same-format runs | Run merging |
| 4 | Hyperlinks | Hyperlink extraction and relationship preservation |
| 5 | Table with cells | Paragraph extraction within tables |
| 6 | Headers and footers | Multi-file extraction |
| 7 | Footnotes | Footnote paragraph extraction |
| 8 | Line breaks and tabs | Standalone InlineCode elements |
| 9 | Empty paragraphs | Skip logic |
| 10 | RTL target language | Bidi markup insertion on rebuild |
| 11 | Large document (50+ pages) | Performance and memory usage |
| 12 | Tracked changes | Current-text-only extraction |
