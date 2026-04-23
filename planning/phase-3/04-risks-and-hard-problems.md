# Phase 3: Risks and Hard Problems

Five specific technical problems most likely to cause architectural mistakes or lost sessions in Phase 3.

---

## Risk 1: XLSX shared string deduplication breaks round-trip fidelity

**What the problem is:**

Multiple cells may reference the same shared string index. If cell A2 and A3 both reference index 5 ("Laptop"), there is one `<si>` to translate. The rebuild replaces the single `<si>` with the translation — correct. But what if the translator gives different translations to what they believe are independent occurrences? The single-index model collapses them.

More critically: the `extract()` step must produce exactly one SegmentPair per unique shared string index, not one per cell. If you iterate cells and create a SegmentPair per cell, you get duplicate SegmentPairs. The XLIFF will contain two identical source segments. The translator translates them once. On rebuild, which translation wins? Undefined behavior.

**Why it is hard:**

The cell iteration loop is the natural place to produce SegmentPairs, but it is the wrong place. You must first collect all unique string indices from all worksheets, then produce one SegmentPair per index, then build the skeleton.

**Decision to make now:**

Iteration order: use the shared string index as the canonical identifier for SegmentPairs. Iterate `sharedStrings.xml` directly (not worksheet cells) to produce segments, skipping indices that are never referenced by any cell. Build the skeleton by scanning worksheet cell references and building an index → placeholder token map.

**Decision to defer:**

What if the translator deliberately wants different translations for identical source strings in different cells? Deferred. This is a rare edge case (identical strings that need different translations are usually terminology problems). If it becomes a real user request, add a "force unique segments" mode that creates separate XLIFF entries per cell reference, each pointing to the same source index but with independent target entries. Requires a more complex skeleton that injects new shared string entries per cell.

---

## Risk 2: DeepL XML tag mode silently corrupts output when source text contains unescaped XML characters

**What the problem is:**

The placeholder encoding wraps source text in `<seg>...</seg>` XML. If the source text contains `&`, `<`, `>`, or `"` (common in technical content, code snippets, or product descriptions), the XML is malformed and DeepL will reject it or return a parse error.

Example: segment content is `Use the <br> tag for line breaks` with no InlineCodes. Encoding it naively as `<seg>Use the <br> tag for line breaks</seg>` produces invalid XML.

**Why it is hard:**

The fix is `htmlspecialchars()` on text nodes before building the XML string. But after DeepL returns the translated XML, the decoder must also `html_entity_decode()` text nodes after parsing — otherwise `&amp;lt;` round-trips as `&lt;` instead of `<`.

This is a subtle encode/decode symmetry bug that is easy to miss in tests (because test input rarely contains `<` or `&` in plain text).

**Decision to make now:**

Always use `DOMDocument` to build the outgoing XML string, never string concatenation. `DOMDocument::createTextNode()` handles escaping automatically. Similarly, parse the response with `DOMDocument::loadXML()` and extract text via `DOMNode::textContent` — this handles decoding automatically. Do not attempt to build or parse MT request/response XML with string operations.

**Decision to defer:**

None. This must be done correctly from the start. Add a unit test with source text containing `<`, `>`, `&`, `"`, and `'` — this test must pass before the DeepL adapter is considered complete.

---

## Risk 3: PSR-18 dependency chain confusion for first-time users

**What the problem is:**

`catframework/mt` requires `psr/http-client` and `psr/http-factory` but ships no implementation. A user who installs the package and tries to instantiate a `DeepLAdapter` without having a concrete HTTP client gets a runtime failure (no class implements `ClientInterface`) or a Composer warning about missing virtual package implementations.

This is a DX (developer experience) problem, not a logic problem. But it will cause confusion and issues for users who are not deep in the PSR ecosystem.

**Why it is hard:**

The correct solution is documentation, not code. But documentation is often unread. The wrong solution is adding a hard dependency on `guzzlehttp/guzzle` — this would eliminate user choice and add unwanted weight for users already using Symfony HTTP Client.

**Decision to make now:**

- In `composer.json`, add a `suggest` block listing known PSR-18 implementations:
  ```json
  "suggest": {
      "guzzlehttp/guzzle": "PSR-18 HTTP client",
      "symfony/http-client": "PSR-18 HTTP client (use with nyholm/psr7)"
  }
  ```
- In the package's `README.md`, include a concrete quickstart with `composer require catframework/mt guzzlehttp/guzzle` as the first example. Do not leave the HTTP client setup to imagination.
- Consider a static factory method on each adapter: `DeepLAdapter::create(string $apiKey): self` that internally checks for a well-known HTTP client class and instantiates it, throwing a helpful `RuntimeException` if none is found. This is an optional convenience, not a required pattern.

**Decision to defer:**

Auto-discovery via `php-http/discovery` (a meta-package that auto-detects installed PSR-18 clients). This exists on Packagist but adds a dependency and magic behavior. Deferred until there is evidence that users are struggling with the manual setup.

---

## Risk 4: PPTX text box inheritance and overriding makes run properties ambiguous

**What the problem is:**

In PPTX, a `<a:r>` run's effective formatting is not necessarily what is in its `<a:rPr>`. The effective formatting is determined by a cascade:

1. Run's own `<a:rPr>` (most specific)
2. Paragraph's `<a:pPr>` default run properties
3. Shape's `<p:txBody><a:lstStyle>` list styles (indexed by paragraph level)
4. Slide layout's corresponding placeholder
5. Slide master's corresponding placeholder
6. Theme

DocxFilter (D9) already decided to compare only explicit `<w:rPr>` elements, ignoring inheritance. The same decision applies here. But in PPTX the inheritance chain is longer and the "no explicit rPr" case is more common — many presentations have runs with empty `<a:rPr/>` or no `<a:rPr>` at all because all formatting comes from the master.

**Why it is hard:**

Two adjacent runs with empty `<a:rPr/>` will be correctly merged (same explicit properties: none). But a run with `<a:rPr b="1"/>` (bold) adjacent to a run with `<a:rPr/>` (no explicit properties, but effectively bold from the master) will be incorrectly split into a "bold" run and a "not bold" run when they are visually identical. This produces unnecessary InlineCodes.

**Decision to make now:**

Apply D9's decision verbatim: compare only explicit `<a:rPr>` elements. Accept that some InlineCodes will be cosmetic noise in heavily master-styled presentations. This is the safe default — incorrect merges produce wrong formatting, extra codes do not.

**Decision to defer:**

Full cascade resolution. This would require parsing the slide master and layout XML for each shape and building an effective-properties cache. Expensive, complex, and only justified if real presentation corpus testing shows unacceptable InlineCode density. Revisit after implementing and testing on 10+ real PPTX files.

---

## Risk 5: MT adapters mask tag-loss errors silently

**What the problem is:**

When DeepL translates a segment with InlineCodes, it sometimes drops, duplicates, or reorders the `<x id="N"/>` placeholder tags. This is not an error from DeepL's perspective — it returned a 200 OK with valid XML. The MT adapter decodes the response and silently produces a Segment where some InlineCodes are missing or the ordering is wrong.

If the translator accepts this MT suggestion without noticing, the rebuilt file will have missing formatting. The QA `tag_consistency` check will catch it — but only if the QA run happens before the file is exported.

**Why it is hard:**

The adapter cannot know whether a missing placeholder is DeepL making an intelligent decision (the tag was inside a word that translated as a different structural unit) or DeepL making a mistake. Both cases look the same at the API response level.

**Decision to make now:**

After decoding the MT response, compare the set of placeholder IDs present in the response against the set that was sent. If any IDs are missing, add a `warning` annotation to the returned Segment's metadata (`["mt_tag_loss" => [1, 3]]` for missing IDs 1 and 3). The translator or reference app can display this warning. Do not throw an exception or silently drop the translation.

The `tag_consistency` QA check already flags missing codes as ERROR. Ensure the QA check is run after MT pre-population in the reference app workflow.

**Decision to defer:**

Automatic tag re-insertion. If a tag is missing from the MT output, it could be re-inserted at the nearest plausible position. This is a complex NLP problem (where does `<bold>` go in the translated sentence?). Deferred indefinitely. Manual correction by the translator is the right answer.
