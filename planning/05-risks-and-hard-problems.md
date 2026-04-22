# Risks and Hard Problems

Four technical problems specific to this domain. Each one can cause an architectural mistake in Phase 1 that forces a painful rewrite in Phase 2 or beyond.

---

## Risk 1: InlineCode spanning across sentence boundaries during segmentation

### What the problem is

When the segmenter splits a paragraph into sentences, formatting tags can span the split point.

Source paragraph (one Segment from the HTML filter):

```
This is {1}bold text. And this is also bold{/1} text.
```

The sentence boundary is after "text." The segmenter must produce:

```
Segment A: This is {1}bold text.{/1*}
Segment B: {1*}And this is also bold{/1} text.
```

The `{/1*}` and `{1*}` are synthetic codes: they don't exist in the original markup. They exist because the segmenter had to close the bold at the end of Segment A and re-open it at the start of Segment B.

### Why it is hard

The complications cascade:

1. **The translator can move codes.** A translator working on Segment B might write `{1*}Et c'est aussi en gras{/1} texte.` But they might also rearrange to `Et c'est {1*}aussi en gras{/1} texte.` The system needs to track that `{1*}` is synthetic and must be present in the target, or the rebuild will produce broken HTML.

2. **De-segmentation for file rebuild.** When rebuilding the HTML file, the filter works at the paragraph level (because that's what it extracted). The segmented sentences need to be merged back into a paragraph. During merge, synthetic codes must be removed and the original spanning tag restored. If the translator deleted a synthetic code in their translation, the merge produces malformed output.

3. **XLIFF representation.** XLIFF 1.2 has `<it pos="open">` (isolated tag) for exactly this case, but many CAT tools handle `<it>` poorly. XLIFF 2.0 has `<sc>` (start code) and `<ec>` (end code) which are designed for spanning, but we're targeting 1.2 in Phase 1.

4. **Nesting.** Bold inside a link inside italic, with the sentence boundary in the middle. All three tags need synthetic close/open codes. Get the nesting order wrong and the rebuild produces `<b><a><i>...</b></a></i>` (malformed HTML).

### How Okapi Framework solves it

Okapi's `TextFragment` uses a "code isolation" concept. When segmentation splits a spanning code, both halves are marked as "isolated" (they have no matching pair in their segment). During merge, the engine scans for isolated codes and re-joins them. The isolated flag is stored on the code object itself.

### Decision needed now

**Add an `isIsolated` boolean to InlineCode.** This is a change to the core data model from Deliverable 2. An isolated code is an OPENING without a matching CLOSING in the same Segment (or vice versa), created by segmentation splitting. Default is `false`. The segmenter sets it to `true` when creating synthetic codes. The de-segmentation step (merge) uses it to detect and re-join spanning tags.

This must be decided now because it affects: InlineCode (core), the segmenter, the XLIFF serializer (maps to `<it>` in 1.2), and every filter's rebuild logic.

### What can be deferred

The exact de-segmentation algorithm (how merge works when the translator has moved or deleted synthetic codes) can be deferred. Phase 1 can enforce a simple rule: synthetic codes are immutable in the target (the editor locks them in place). Phase 2 can add smarter merge logic that handles code rearrangement.

---

## Risk 2: Skeleton fidelity and serialization

### What the problem is

The skeleton is the original file's structure with translatable text replaced by placeholders. The filter stores it in `BilingualDocument::$skeleton` during extract, and uses it during rebuild to inject translations. Two sub-problems:

**Sub-problem A: rebuild must produce identical non-text content.** For HTML, this means every attribute, comment, script block, CSS class, and whitespace character in the original must survive extract → rebuild unchanged. For DOCX (Phase 2), this means images, styles, headers, footers, embedded objects, tracked changes, and revision history must survive.

**Sub-problem B: the skeleton must be serializable.** When saving a BilingualDocument to XLIFF, the skeleton must be persisted. When reloading the XLIFF, the skeleton must be reconstructable. For HTML, the skeleton is a string (HTML with placeholder tokens) and can be embedded in XLIFF. For DOCX, the skeleton is an entire ZIP archive (minus the text content of `document.xml`), which is too large to embed.

### Why it is hard

The `$skeleton` property is typed as `array`, which is maximally flexible but means there's no enforced contract about what's inside. Each filter puts different things in the skeleton. The XLIFF serializer doesn't know what's in there and can't make assumptions.

If Phase 1 stores HTML skeletons as embedded strings in XLIFF, and Phase 2 needs to store DOCX skeletons as external file references, the XLIFF serializer's skeleton handling needs to support both strategies. If the Phase 1 approach is too naive, it'll need rewriting.

### How Okapi Framework solves it

Okapi uses a "skeleton-based" approach where the skeleton is a file (usually in a custom format) stored alongside the bilingual file. The bilingual file (XLIFF) references the skeleton via `<skeleton><external-file href="skeleton.skl"/></skeleton>`. The skeleton format is filter-specific and opaque to everything except the filter.

### Decision needed now

**Adopt the external skeleton file approach from day one.** Even in Phase 1 with HTML, store the skeleton as a separate file alongside the XLIFF, not embedded in it. The XLIFF `<skeleton>` element references the file path. The skeleton file format is filter-specific (for HTML: the HTML string with placeholder tokens, saved as-is; for DOCX in Phase 2: a modified ZIP archive).

This means "saving a BilingualDocument" always produces two files: the XLIFF and the skeleton. They travel together. This is slightly more complex than embedding, but it avoids a redesign when DOCX skeletons won't fit inside XLIFF.

Concrete convention: if the XLIFF is `project.xlf`, the skeleton is `project.xlf.skl`. The XLIFF serializer writes both and reads both.

### What can be deferred

The skeleton format for each filter can be defined when that filter is built. The XLIFF serializer just needs to save/load an opaque blob associated with the document. Whether that blob is an HTML string or a ZIP archive is the filter's concern.

---

## Risk 3: Unicode segmentation correctness for Hindi, Urdu, and Arabic

### What the problem is

SRX segmentation rules use regex patterns to find sentence boundaries. Standard rules assume Latin-script conventions that break for your target languages.

The typical Latin-script sentence break rule:

```
beforeBreak: [\.\?!]+
afterBreak: \s+\p{Lu}
```

This means: break after `.?!` followed by whitespace and an uppercase letter.

**Hindi (Devanagari):** The sentence-ending mark is the purna viram `।` (U+0964), not a period. Hindi has no uppercase/lowercase distinction. The `\p{Lu}` (uppercase letter) afterBreak pattern matches nothing in Devanagari. Result: zero sentence breaks detected. Every paragraph stays as one segment.

**Urdu (Arabic script, Nastaliq):** Sentence-ending mark is `۔` (U+06D4, Arabic full stop) or sometimes `.` (U+002E). RTL script. No case distinction. Same afterBreak problem as Hindi.

**Mixed-language content:** A Hindi document with embedded English brand names, or an English document with Hindi transliteration. The segmenter encounters multiple scripts in the same text. Which language's rules apply?

### Why it is hard

1. **Purna viram and Arabic full stop are not in the default "sentence-ending punctuation" character class.** If the default SRX rules only list `[\.\?!]` as sentence enders, Hindi and Urdu text will never segment. Every paragraph = one huge segment. TM matching will be useless (matching paragraph-length strings), the editor will be unwieldy, and word count will be wrong.

2. **No case = no easy afterBreak signal.** In Latin scripts, the uppercase letter after whitespace is a reliable sentence-start signal. Hindi and Urdu have no equivalent signal. The afterBreak pattern must use a weaker heuristic: whitespace followed by any letter (`\p{L}`). This produces false breaks (every space followed by a letter looks like a sentence boundary).

3. **PHP's regex engine and Unicode.** PHP's `preg_match` with the `u` flag supports `\p{Devanagari}`, `\p{Arabic}`, `\p{Lu}`, etc. But some Unicode properties require ICU (available via `ext-intl`). If a critical property isn't available in PCRE's Unicode support, the SRX rules can't express the necessary patterns.

### How Translate Toolkit and ICU solve it

ICU (International Components for Unicode) has sentence break rules for most languages, defined in Unicode UAX #29 (Unicode Text Segmentation). These rules handle Hindi, Arabic, and CJK correctly. They use Unicode character properties rather than explicit punctuation lists.

Translate Toolkit uses ICU's break iterators via Python bindings. PHP has access to the same ICU data through `ext-intl`'s `IntlBreakIterator::createSentenceInstance()`. This is a complete, tested sentence segmenter for any ICU-supported locale.

### Decision needed now

**Two decisions:**

1. **Ship language-specific SRX rules that include the correct sentence-ending punctuation for Hindi (U+0964, U+0965), Urdu/Arabic (U+06D4, U+061F), and common scripts from day one.** The afterBreak pattern for these languages should be `\s+\p{L}` (any letter after whitespace), not `\s+\p{Lu}`. Accept that this is a weaker heuristic and will occasionally over-segment.

2. **Consider using `IntlBreakIterator` as a fallback/alternative to SRX rules.** The SRX engine is the primary segmenter (it's the standard, it's configurable, and users can load custom rules). But for languages where SRX rules are inadequate, `IntlBreakIterator::createSentenceInstance($locale)` is a battle-tested alternative backed by the full ICU dataset. The `SegmentationEngineInterface` doesn't prescribe SRX; an ICU-based implementation would satisfy the same interface.

Don't build the ICU implementation in Phase 1. But DO design the segmentation interface so it's pluggable (it already is), and DO test the SRX rules with real Hindi and Urdu text before considering Phase 1 complete.

### What can be deferred

CJK segmentation (Chinese, Japanese, Korean) can wait until there's demand. CJK has fundamentally different rules (no spaces between words, sentence boundaries are clearer with `。` but no afterBreak whitespace). Supporting CJK properly may require the ICU fallback, which is Phase 2.

---

## Risk 4: XLIFF round-trip fidelity for inline codes

### What the problem is

When a BilingualDocument is saved to XLIFF and reloaded, every property of every InlineCode must survive the round trip exactly. If any data is lost or corrupted, the filter cannot rebuild the original file.

The critical property is `InlineCode::$data`, which contains the original markup from the source file. For HTML in Phase 1, this is literal HTML tag text (e.g., `<a href="https://example.com">`). For DOCX in Phase 2, this will be raw OOXML (e.g., `<w:rPr><w:b/><w:sz w:val="24"/></w:rPr>`).

XLIFF 1.2 inline elements store the original code content as text:

```xml
<bpt id="1">&lt;a href="https://example.com"&gt;</bpt>Click here<ept id="1">&lt;/a&gt;</ept>
```

### Why it is hard

1. **XML escaping in XML.** The InlineCode data is XML content (HTML tags, OOXML) stored inside another XML document (XLIFF). It must be escaped. Standard XML escaping (`&lt;`, `&gt;`, `&amp;`) works but makes the XLIFF hard to read and debug. CDATA sections avoid escaping but can't contain `]]>` (rare but possible in OOXML).

2. **Other CAT tools may mangle our XLIFF.** If a translator opens our XLIFF in Trados or memoQ, those tools may rewrite the inline elements. Trados might strip custom attributes, renumber IDs, or normalize whitespace inside `<bpt>`/`<ept>`. If the modified XLIFF is loaded back into our framework, the InlineCode data may be corrupted.

3. **`displayText` has no standard XLIFF 1.2 home.** XLIFF 2.0 has `equiv-text` for this. XLIFF 1.2 does not. We'd need a custom attribute (e.g., `<bpt id="1" equiv-text="&lt;b&gt;">`) or a convention. Custom attributes risk being stripped by other tools.

4. **Isolated codes (from Risk 1).** An isolated OPENING code maps to XLIFF 1.2's `<it pos="open">` element. But `<it>` is poorly supported by many CAT tools. Saving isolated codes as `<it>` is correct per the XLIFF spec but may cause compatibility issues.

### How existing tools handle it

Most CAT tools (Trados, memoQ) use proprietary bilingual formats internally (SDLXLIFF, MQXLIFF) that extend XLIFF with custom namespaces. When exporting "clean" XLIFF for exchange, they accept some data loss in inline codes. Okapi stores the full original codes and uses XLIFF namespaces to preserve extra attributes.

### Decisions needed now

1. **Use XML entity escaping (not CDATA) for InlineCode.data inside XLIFF `<bpt>`/`<ept>`/`<ph>`.** CDATA is fragile (can't nest, `]]>` issue). Entity escaping is verbose but universally safe and most likely to survive round-tripping through other tools.

2. **Store `displayText` in a custom namespaced attribute: `<bpt id="1" catfw:equiv-text="&lt;b&gt;">`.** Define a namespace `xmlns:catfw="urn:catframework"` on the XLIFF root. Other tools will either preserve unknown namespaced attributes (most do) or strip them (acceptable, displayText is regenerable).

3. **Store isolated codes as `<it>` per XLIFF 1.2 spec.** Accept that compatibility with other tools is imperfect for segmented files with spanning codes. This is a known limitation of XLIFF 1.2 that XLIFF 2.0 fixes. Document it.

4. **Write a round-trip test suite from day one.** For every filter: extract a test file, save to XLIFF, reload from XLIFF, rebuild the file, and binary-compare the rebuilt file against expected output. This catches regressions immediately. This is the single most valuable test you can write.

### What can be deferred

XLIFF 2.0 support (which solves the `equiv-text` and isolated code problems cleanly) is Phase 2. The Phase 1 approach with XLIFF 1.2 + custom namespace works and is compatible enough for practical use.

---

## Summary: Decisions Needed Now

| # | Decision | Recommended choice |
|---|---|---|
| 1 | Add `isIsolated` to InlineCode? | Yes. Required for segmentation spanning. |
| 2 | Skeleton storage: embedded or external file? | External file (`project.xlf.skl`), even in Phase 1. |
| 3 | Default SRX rules for Hindi/Urdu? | Include correct punctuation (U+0964, U+06D4) and use `\p{L}` afterBreak. Test with real text. |
| 4 | Consider ICU fallback segmenter? | Design for it (interface is already pluggable). Don't build in Phase 1. |
| 5 | InlineCode.data in XLIFF: CDATA or escaping? | Entity escaping. Safest for round-tripping. |
| 6 | displayText in XLIFF 1.2? | Custom namespaced attribute `catfw:equiv-text`. |
| 7 | Isolated codes in XLIFF 1.2? | Use `<it>` per spec. Accept compatibility limitations. |
| 8 | Round-trip test suite? | Build from day one. Highest-value test investment. |
