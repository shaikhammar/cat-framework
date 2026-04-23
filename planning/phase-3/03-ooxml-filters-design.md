# Phase 3: OOXML Filters Design (XLSX + PPTX)

Detailed design for `catframework/filter-xlsx` and `catframework/filter-pptx`. Read alongside the scope doc. Both packages follow patterns established by `catframework/filter-docx`, so read that implementation first.

---

## filter-xlsx

### The Core Problem: Shared Strings

DOCX stores each paragraph's text inline in the document XML. XLSX does not. Instead, Excel maintains a global string pool in `xl/sharedStrings.xml`. A cell in any worksheet references a string by its zero-based index in that pool.

Example:

```xml
<!-- xl/sharedStrings.xml -->
<sst count="3" uniqueCount="3">
  <si><t>Product Name</t></si>   <!-- index 0 -->
  <si><t>Price</t></si>          <!-- index 1 -->
  <si><t>Laptop</t></si>         <!-- index 2 -->
</sst>

<!-- xl/worksheets/sheet1.xml -->
<c r="A1" t="s"><v>0</v></c>  <!-- "Product Name" -->
<c r="B1" t="s"><v>1</v></c>  <!-- "Price" -->
<c r="A2" t="s"><v>2</v></c>  <!-- "Laptop" -->
<c r="A3" t="s"><v>2</v></c>  <!-- "Laptop" again — same index! -->
```

If A2 and A3 share index 2, there is only one `<si>` to translate. The rebuilt file should have both cells reference the same translated string. This is correct behavior: if the source had "Laptop" in two cells, the translated file should have the same translation in both.

### Extraction Algorithm

1. Parse `xl/sharedStrings.xml`. Build `$sharedStrings: array<int, SiEntry>` where `SiEntry` holds the plain text and optional rich text runs.
2. Parse each worksheet. For each shared-string cell (`t="s"`): record the shared string index used by that cell. Collect a set of unique indices that appear in at least one cell.
3. For each unique index in that set: extract the `<si>` content as a Segment (handling rich text runs → InlineCodes using the same DocxFilter run-merging logic).
4. Produce one SegmentPair per unique shared string index.
5. Store a mapping `$indexToCellRefs: array<int, array<string>>` (shared string index → list of cell addresses like "sheet1!A2") in the BilingualDocument metadata. This is needed for the skeleton to know which cells to update on rebuild — but since the skeleton replaces the `<si>` elements directly, cell ref tracking is only needed for diagnostics and is optional.

### Rich Text in Shared Strings

An `<si>` element can contain either:
- A simple `<t>` text element.
- Multiple `<r>` run elements, each with `<rPr>` (run properties) and `<t>` text.

The `<r>` / `<rPr>` structure in `sharedStrings.xml` is DrawingML-like, not WordprocessingML. The namespace is different (`xmlns:x` or no namespace prefix) but the concept is the same. Apply the same run-merging logic: adjacent runs with identical `<rPr>` are merged, formatting changes become InlineCode pairs.

### Skeleton Strategy

The skeleton is a copy of the full XLSX ZIP with the translatable `<si>` elements in `sharedStrings.xml` replaced by placeholder tokens:

```xml
<!-- Skeleton sharedStrings.xml -->
<sst count="3" uniqueCount="3">
  <si><t>{{SEG:001}}</t></si>
  <si><t>{{SEG:002}}</t></si>
  <si><t>{{SEG:003}}</t></si>
</sst>
```

Non-translatable `<si>` elements (pure numbers stored as strings, formula result caches) retain their original text in the skeleton.

On rebuild:
1. Open skeleton ZIP.
2. Replace each `{{SEG:N}}` in `sharedStrings.xml` with the translated target text (or source text if untranslated). Reconstruct `<r>` elements for rich text.
3. Delete `xl/calcChain.xml` from the rebuilt ZIP — this caches formula evaluation order and becomes stale when the string pool changes. Excel regenerates it automatically on open.
4. Write the modified ZIP to the output path.

### Cell Types to Handle

| Cell attribute `t` | Meaning | Action |
|---|---|---|
| `s` | Shared string index | Translate (primary case) |
| `inlineStr` | Inline string (no shared string) | Translate directly in cell XML |
| `str` | Formula-computed string | Skip (translate formula result is wrong; formula is in the cell, not a user-authored string) |
| `n` (default) | Numeric | Skip |
| `b` | Boolean | Skip |
| `e` | Error | Skip |

### What to Skip

- Cells where the shared string content is purely numeric (e.g., a year "2024" stored as a string). Heuristic: if the plain text matches `/^\s*[\d.,\-+%$€£¥]+\s*$/`, skip.
- Cells in hidden rows/columns (check `hidden="1"` on `<row>` and column definitions). Configurable, default: skip hidden rows.
- Charts (`xl/charts/`): no text extraction. Preserved in skeleton.
- Comments (`xl/comments{N}.xml`): no extraction. Preserved in skeleton.

---

## filter-pptx

### File Structure

A `.pptx` file is a ZIP containing:

```
ppt/
  presentation.xml          — slide list, slide size
  slides/
    slide1.xml              — slide 1 content
    slide2.xml              — slide 2 content
    _rels/
      slide1.xml.rels       — slide 1 relationships (notes, layout, master)
  notesSlides/
    notesSlide1.xml         — speaker notes for slide 1
  slideLayouts/             — templates (DO NOT TRANSLATE)
  slideMasters/             — master templates (DO NOT TRANSLATE)
  theme/                    — theme colors/fonts (DO NOT TRANSLATE)
  media/                    — images (skip)
```

### DrawingML Text Structure

Slide text lives in shapes (`<p:sp>`). Each shape has a text body:

```xml
<p:sp>
  <p:nvSpPr>...</p:nvSpPr>   <!-- shape metadata -->
  <p:spPr>...</p:spPr>        <!-- geometry, position -->
  <p:txBody>
    <a:bodyPr/>
    <a:lstStyle/>
    <a:p>                     <!-- paragraph -->
      <a:r>                   <!-- run -->
        <a:rPr b="1"/>        <!-- run properties (bold) -->
        <a:t>Hello</a:t>      <!-- text -->
      </a:r>
      <a:r>
        <a:rPr/>
        <a:t> world</a:t>
      </a:r>
    </a:p>
  </p:txBody>
</p:sp>
```

This is structurally identical to the DocxFilter run/paragraph model. Reuse the same run-merging and InlineCode generation logic. The only difference is XML namespaces (`a:` DrawingML instead of `w:` WordprocessingML) and element names (`<a:t>` instead of `<w:t>`, `<a:rPr>` instead of `<w:rPr>`).

### What to Extract

**Extract:**
- `<p:sp>` shapes on each slide (text placeholders, text boxes, content placeholders).
- `<p:sp>` shapes in notes slides (`notesSlides/notesSlide{N}.xml`). Tag these with metadata `context: "notes"`.

**Skip:**
- `<p:pic>` image shapes (no text to translate).
- `<p:graphicFrame>` shapes containing charts or SmartArt — too complex, preserved in skeleton.
- `<p:cxnSp>` connector shapes — rarely contain text.
- Slide master shapes (`slideMasters/`).
- Slide layout shapes (`slideLayouts/`).

### Determining Which Shapes Have Translatable Text

Walk the slide shape tree and check each `<p:sp>` for a `<p:txBody>` descendant. If it exists, check all `<a:t>` elements for non-empty content. If any `<a:t>` is non-empty, the shape is translatable.

### Slide Ordering

Extract slides in the order they appear in `ppt/presentation.xml`'s `<p:sldIdLst>`. This preserves the reading order the translator expects.

### Skeleton Strategy

Same string-replacement approach as DocxFilter: copy the ZIP, replace `<a:t>` content within translatable shapes with placeholder tokens. Non-text content (shape geometry, images, master references) is untouched.

On rebuild: replace placeholder tokens with translated text, reconstruct `<a:r>` elements from target InlineCodes.

### Notes Slides

`ppt/notesSlides/notesSlide{N}.xml` contains speaker notes. The relationship between `slide{N}.xml` and `notesSlide{N}.xml` is defined in `ppt/slides/_rels/slide{N}.xml.rels`:

```xml
<Relationship Type=".../notesSlide" Target="../notesSlides/notesSlide1.xml"/>
```

Parse this relationship file to link each slide to its notes slide. Extract notes content as additional SegmentPairs tagged with `context: "notes"` in BilingualDocument metadata.

---

## Shared Implementation Note

Both filters are structurally similar to DocxFilter. Before implementing each one, check DocxFilter's:
- Run-merging algorithm (can be extracted to a shared `RunMerger` utility in `core` if it becomes duplicated across all three OOXML filters)
- Skeleton token generation (`{{SEG:NNN}}` with zero-padded sequential numbering)
- ZIP copy strategy (use `ext-zip`'s `ZipArchive::open()` + `ZipArchive::getFromName()` / `ZipArchive::addFromString()` pattern)

If the run-merging logic ends up duplicated across DocxFilter, XlsxFilter, and PptxFilter, extract it into a `CatFramework\Core\Util\OoxmlRunMerger` class in `catframework/core`. Do not create a new shared package for this — it is a single utility class. Make this refactor the first task of the filter-xlsx implementation session, before writing new code.
