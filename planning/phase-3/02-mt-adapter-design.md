# Phase 3: MT Adapter Design

Detailed design for `catframework/mt`. Complements the scope doc. Read this before starting the implementation session.

---

## Package Structure

```
catframework/mt/
  src/
    AbstractMtAdapter.php
    NullMtAdapter.php
    DeepL/
      DeepLAdapter.php
      DeepLLanguageMapper.php
    Google/
      GoogleTranslateAdapter.php
    Exception/
      MtException.php          (re-export from core or extend it)
  tests/
    DeepLAdapterTest.php
    GoogleTranslateAdapterTest.php
    NullMtAdapterTest.php
    InlinePlaceholderTest.php
  composer.json
```

---

## PSR-18 Dependency Strategy

The package declares these in `require`:
```json
"require": {
    "php": "^8.2",
    "catframework/core": "*",
    "psr/http-client": "^1.0",
    "psr/http-factory": "^1.0",
    "psr/http-message": "^1.1 || ^2.0"
}
```

All three are interface packages (no implementation, zero transitive deps). The user is responsible for providing a concrete HTTP client. Common choices they already have:

- Laravel apps: `guzzlehttp/guzzle` (included with Laravel)
- Standalone PHP: `guzzlehttp/guzzle`, `symfony/http-client`, `buzz/browser`

The adapters receive `ClientInterface` and `RequestFactoryInterface` as constructor arguments. No auto-discovery, no virtual packages in `require-dev` for "suggest only". Keep it explicit.

```php
public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly RequestFactoryInterface $requestFactory,
    private readonly StreamFactoryInterface $streamFactory,
    private readonly string $apiKey,
)
```

**Testing:** Tests use a mock `ClientInterface` that returns pre-recorded HTTP responses. No real API calls in the test suite. Record real responses manually into fixture files and replay them. This avoids API key requirements in CI.

---

## InlineCode Placeholder Conversion

This is the core shared logic in `AbstractMtAdapter`. Both adapters need it; only DeepL uses the output XML directly.

### Encoding

```
Input segment:  ["Hello ", InlineCode(id=1, type=open, data="<b>"), "world", InlineCode(id=2, type=close, data="</b>"), "!"]
Output string:  "Hello <x id=\"1\"/> world<x id=\"2\"/>!"
```

Rules:
- Each InlineCode becomes `<x id="{originalId}"/>` (self-closing, no content).
- The placeholder element name `x` is chosen because DeepL's `ignore_tags=x` parameter targets it by name — this tells DeepL not to translate or paraphrase content inside `<x>` tags.
- Build a map `[int $placeholderId => InlineCode $code]` to restore after MT.
- Wrap the entire string in a root element before sending to any XML-mode API: `<seg>Hello <x id="1"/> world<x id="2"/>!</seg>`. This ensures the string is valid XML even if the source text contains bare `&`, `<`, `>`. Escape source text content using `htmlspecialchars()` before substitution.

### Decoding

After receiving the MT response string:
1. Parse it as XML (wrap in `<seg>` if not already).
2. Walk child nodes: text nodes → plain text, `<x id="N"/>` elements → look up original InlineCode by N.
3. Reconstruct a Segment from the sequence of strings and InlineCodes.
4. If an `<x id="N"/>` appears in the response but N is not in the map, treat as plain text `{N}` (defensive).
5. If an `<x id="N"/>` is missing from the response (MT dropped it), log a warning and omit the code. The QA `tag_consistency` check will catch this in a subsequent run.

### When XML parsing fails

If the MT response is not valid XML (e.g., the API mangled a placeholder), fall back to: strip all `<x .../>` tags from the response string via regex, return a plain-text Segment with no InlineCodes. Never throw an exception for a malformed MT response — degraded output is better than a crash.

---

## DeepL Adapter Design

### API Request (translate)

```
POST https://api.deepl.com/v2/translate
Authorization: DeepL-Auth-Key {apiKey}
Content-Type: application/x-www-form-urlencoded

text[]=Hello+<x+id%3D%221%22%2F>+world&
source_lang=EN&
target_lang=DE&
tag_handling=xml&
ignore_tags=x
```

Multiple segments (`translateBatch`) → multiple `text[]` values in the same request. DeepL processes them as a batch, returning one `translations[]` entry per input, in order.

### Language Code Mapping

DeepL uses its own codes, not BCP 47. The `DeepLLanguageMapper` class handles the conversion.

Key differences:
- Source lang: DeepL accepts 2-letter codes only, no region (EN, not EN-US or en-US).
- Target lang: some targets require region (EN-GB or EN-US, not EN; PT-BR or PT-PT, not PT).
- Chinese: DeepL uses ZH (source) and ZH-HANS / ZH-HANT (target).
- Arabic: DeepL source = AR, no target variants.
- Hindi/Urdu: as of 2026, DeepL supports Hindi (HI). Urdu is not supported — throw `MtException` with `LANGUAGE_NOT_SUPPORTED` code when requested.

The mapper is a simple lookup table. Do not hard-code a "supported languages" list — it changes. If a mapping is not found, pass the BCP 47 code uppercased and let the DeepL API return an error, which maps to `MtException`.

### Error Handling

| HTTP status | Meaning | Action |
|---|---|---|
| 200 | Success | parse response |
| 400 | Bad request (bad params) | throw MtException |
| 403 | Auth failure | throw MtException |
| 429 | Rate limit | retry up to 3 times with 1s backoff, then throw |
| 456 | Quota exceeded | throw MtException with distinct code |
| 5xx | Server error | retry up to 3 times, then throw |

---

## Google Translate Adapter Design

### API Request (translate)

```
POST https://translation.googleapis.com/v3/projects/{projectId}/locations/global:translateText
X-Goog-Api-Key: {apiKey}
Content-Type: application/json

{
  "sourceLanguageCode": "en",
  "targetLanguageCode": "de",
  "contents": ["Hello world"],
  "mimeType": "text/plain"
}
```

Note `mimeType: "text/plain"` — Google v3 does support `text/html` mode, but HTML mode does not reliably preserve `<x id="N"/>` placeholder tags and may paraphrase their content. Safer to strip codes and use plain text. Document this in the adapter's docblock.

### InlineCodes Strategy for Google

Since codes are stripped:
1. Extract plain text from source Segment (ignore all InlineCodes).
2. Send plain text to Google.
3. Return a new Segment containing only the translated plain text, no InlineCodes.

The translator must manually re-insert codes in the editor. The QA `tag_consistency` check will flag the missing codes as an ERROR, prompting the translator to fix them.

This is a known, documented tradeoff. Google Translate is a cost-effective fallback; DeepL is preferred for documents with heavy inline formatting.

### Language Code Mapping

Google v3 uses BCP 47 codes directly. Minimal normalization: lowercase the code. No mapping table needed. If the language is unsupported, Google returns an error which maps to `MtException`.

### Batch Support

Google v3 does not support multiple source strings in a single `translateText` call. `translateBatch()` loops over `translate()` with a delay between requests.

Simple delay: `usleep(100_000)` (100ms) between calls. This is a courtesy throttle, not a rate limiter. If quota is exceeded, the adapter catches the 429 response and retries with exponential backoff (3 retries: 1s, 2s, 4s), then throws.

---

## NullMtAdapter

Used in tests and as a safe default in factory methods:

```php
public function translate(Segment $source, string $sl, string $tl): Segment
{
    return new Segment('', []);
}

public function translateBatch(array $sources, string $sl, string $tl): array
{
    return array_map(fn() => new Segment('', []), $sources);
}

public function getProviderId(): string
{
    return 'null';
}
```

---

## Test Strategy

- **Unit tests for placeholder encoding/decoding:** feed Segments with various InlineCode configurations, assert the XML string is correct, parse it back, assert the Segment is reconstructed correctly. Cover: no codes, one code pair, multiple code pairs, code at start, code at end, isolated codes.
- **Unit tests for DeepLAdapter:** mock HTTP client returning pre-recorded JSON fixtures. Test: successful translation, language not supported, rate limit retry, quota exceeded.
- **Unit tests for GoogleTranslateAdapter:** same fixture approach. Test: plain text (codes stripped), batch loop, retry on 429.
- **Round-trip integration test (optional, skipped in CI):** guarded by an env var `DEEPL_API_KEY`. If set, run a real round-trip through DeepL with a 3-segment test document. Assert translated segments are non-empty and placeholder-restored InlineCodes match the source.
