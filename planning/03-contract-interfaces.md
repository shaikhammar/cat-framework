# Contract Interfaces

All interfaces live in `catframework/core`. Namespace: `CatFramework\Core\Contract`.

Supporting types introduced by these interfaces (not part of the 7 core models but needed for return types) are noted inline.

---

## FileFilterInterface

Extracts translatable content from a source file and rebuilds the file with translations injected.

```php
namespace CatFramework\Core\Contract;

use CatFramework\Core\Model\BilingualDocument;

interface FileFilterInterface
{
    /**
     * Can this filter handle the given file?
     *
     * Checks file extension and optionally MIME type. Does NOT open or
     * parse the file (that happens in extract). This is a cheap gate
     * for filter selection.
     *
     * @param string $filePath Path to the source file.
     * @param string|null $mimeType Optional MIME type hint (from upload, HTTP header, etc.).
     */
    public function supports(string $filePath, ?string $mimeType = null): bool;

    /**
     * Parse the source file into a BilingualDocument.
     *
     * Extracts all translatable text as SegmentPairs (target = null).
     * Stores a skeleton in the BilingualDocument so rebuild() can
     * reconstruct the original file structure.
     *
     * Does NOT perform segmentation. Each SegmentPair.source is one
     * structural unit from the file (a paragraph in DOCX, a text node
     * in HTML, a line in plaintext). Sentence-level segmentation is
     * the SegmentationEngine's job.
     *
     * @throws \CatFramework\Core\Exception\FilterException On parse failure.
     */
    public function extract(
        string $filePath,
        string $sourceLanguage,
        string $targetLanguage,
    ): BilingualDocument;

    /**
     * Rebuild the source file with translations from the BilingualDocument.
     *
     * Uses the skeleton stored during extract() plus the target Segments
     * to produce a translated version of the original file. Non-translatable
     * content (images, styles, metadata) is preserved exactly.
     *
     * @param BilingualDocument $document Must have been produced by this filter.
     * @param string $outputPath Where to write the translated file.
     * @throws \CatFramework\Core\Exception\FilterException On rebuild failure.
     */
    public function rebuild(BilingualDocument $document, string $outputPath): void;

    /**
     * File extensions this filter handles, lowercase with leading dot.
     * e.g., ['.docx'], ['.html', '.htm'], ['.txt'].
     *
     * @return string[]
     */
    public function getSupportedExtensions(): array;
}
```

**Design tradeoff: single interface vs. split Reader/Writer.**

I kept extract and rebuild on the same interface because the filter that created the skeleton is the only code that can interpret it. Splitting into `FileReaderInterface` and `FileWriterInterface` would create two interfaces that are always implemented by the same class, with no practical benefit. If a consumer only needs extraction (e.g., a word count tool), they can still use this interface and ignore `rebuild`.

**Design tradeoff: file path vs. stream input.**

`extract` takes a file path, not a stream or string. Reason: DOCX/XLSX/PPTX filters need random access to the ZIP archive, which streams don't support. File paths work universally. The caller is responsible for saving uploaded content to a temp file first, which is trivial.

---

## SegmentationEngineInterface

Splits paragraph-level text units into sentence-level segments.

```php
namespace CatFramework\Core\Contract;

use CatFramework\Core\Model\Segment;

interface SegmentationEngineInterface
{
    /**
     * Split a paragraph-level Segment into sentence-level Segments.
     *
     * The input is a structural unit from the file filter (one paragraph,
     * one table cell, one text node). Output is one or more sentence-level
     * Segments. If the input is a single sentence, the output array
     * contains one element.
     *
     * InlineCodes in the input are distributed to the correct output
     * Segments based on their position. Codes that span a split boundary
     * are handled by closing the tag at the end of one segment and
     * re-opening it at the start of the next (mirroring XLIFF's approach).
     *
     * @param Segment $input A paragraph-level Segment (with InlineCodes).
     * @param string $languageCode BCP 47 code. Determines which SRX rules apply.
     * @return Segment[] Sentence-level segments. Never empty (returns [$input] if no splits).
     */
    public function segment(Segment $input, string $languageCode): array;

    /**
     * Load segmentation rules from an SRX file.
     *
     * Replaces any previously loaded rules for the languages defined
     * in the SRX file. Rules are additive across multiple loadRules
     * calls for different languages.
     *
     * @param string $srxFilePath Path to an SRX 2.0 file.
     * @throws \CatFramework\Core\Exception\SegmentationException On invalid SRX.
     */
    public function loadRules(string $srxFilePath): void;
}
```

**Why only 2 methods:**

Segmentation is a focused operation. The engine loads rules and applies them. No configuration beyond SRX rules is needed at the interface level. Implementations can offer tuning (custom break rules, abbreviation lists) through their constructor, not through the contract.

**Tradeoff: Segment in vs. plain string in.**

Input is `Segment` (not `string`) because the segmenter must know where InlineCodes are. When a sentence boundary falls inside a bold span, the segmenter needs to split the codes correctly (close `{/1}` at end of segment N, re-open `{1}` at start of segment N+1). This is one of the hard problems (see Deliverable 5).

---

## TranslationMemoryInterface

Stores translation units and retrieves matches against source segments.

```php
namespace CatFramework\Core\Contract;

use CatFramework\Core\Model\MatchResult;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\TranslationUnit;

interface TranslationMemoryInterface
{
    /**
     * Find matching TUs for a source segment.
     *
     * Returns matches sorted by score descending. Exact matches (1.0)
     * first, then fuzzy matches. The engine determines the scoring
     * algorithm (Levenshtein, edit distance, etc.).
     *
     * @param Segment $source The source segment to match against.
     * @param string $sourceLanguage BCP 47 code.
     * @param string $targetLanguage BCP 47 code.
     * @param float $minScore Minimum score threshold (0.0-1.0). Default 0.7.
     * @param int $maxResults Maximum matches to return. Default 5.
     * @return MatchResult[] Sorted by score descending. Empty if no matches.
     */
    public function lookup(
        Segment $source,
        string $sourceLanguage,
        string $targetLanguage,
        float $minScore = 0.7,
        int $maxResults = 5,
    ): array;

    /**
     * Store a translation unit in the TM.
     *
     * If an exact duplicate (same source text, same language pair) exists,
     * the behavior is: update the existing entry with the new target and
     * metadata (most-recent-wins strategy, same as Trados/memoQ default).
     */
    public function store(TranslationUnit $unit): void;

    /**
     * Import TUs from a TMX file.
     *
     * @return int Number of TUs imported (including updates to existing entries).
     * @throws \CatFramework\Core\Exception\TmException On parse or storage failure.
     */
    public function import(string $tmxFilePath): int;

    /**
     * Export all TUs to a TMX file.
     *
     * @return int Number of TUs exported.
     */
    public function export(string $tmxFilePath): int;
}
```

**Why no `delete` method:**

Deletion is a maintenance operation, not a translation workflow operation. Adding it to the interface forces every implementation to support it. Implementations that need deletion (the SQLite backend) can expose it on their concrete class. The interface stays focused on the read/write/exchange operations that all TM consumers need.

**Tradeoff: `minScore` as a float.**

Float comparison is imprecise, but TM scores are inherently imprecise (different algorithms produce different scores for the same pair). Using float with a sensible default (0.7 = industry standard "useful fuzzy" threshold) is pragmatic. An integer percentage (70) would be an alternative but less expressive.

---

## TerminologyProviderInterface

Recognizes terms in source text and provides approved translations.

```php
namespace CatFramework\Core\Contract;

interface TerminologyProviderInterface
{
    /**
     * Scan running text and find all known terms.
     *
     * Scans the source text for any term in the terminology database
     * for the given language pair. Returns all matches with their
     * positions, so the editor can highlight recognized terms.
     *
     * @param string $sourceText Plain text to scan (no inline codes).
     * @param string $sourceLanguage BCP 47 code.
     * @param string $targetLanguage BCP 47 code.
     * @return TermMatch[] All recognized terms with positions. May overlap.
     */
    public function recognize(
        string $sourceText,
        string $sourceLanguage,
        string $targetLanguage,
    ): array;

    /**
     * Look up a specific term or phrase.
     *
     * Unlike recognize() which scans text, this searches for a specific
     * query string. Used when a translator selects text and asks
     * "what's the approved translation for this?"
     *
     * @return TermEntry[] All matching entries (may be multiple if the
     *     term has different translations in different domains).
     */
    public function lookup(
        string $term,
        string $sourceLanguage,
        string $targetLanguage,
    ): array;

    /**
     * Import terminology from a TBX file.
     *
     * @return int Number of entries imported.
     * @throws \CatFramework\Core\Exception\TerminologyException On parse failure.
     */
    public function import(string $tbxFilePath): int;

    /**
     * Add a single term entry.
     */
    public function addEntry(TermEntry $entry): void;
}
```

**Supporting types** (defined in `CatFramework\Core\Model`):

```php
readonly class TermEntry
{
    public function __construct(
        /** The source-language term. */
        public string $sourceTerm,

        /** Approved target-language translation. */
        public string $targetTerm,

        public string $sourceLanguage,
        public string $targetLanguage,

        /** Optional definition or usage note. */
        public ?string $definition = null,

        /** Domain or subject area (e.g., "legal", "medical"). */
        public ?string $domain = null,

        /**
         * If true, this is a "forbidden" entry: the target term should
         * NOT be used. QA checks flag it if found in a translation.
         * Example: "click" should be translated as "tap" not "press" in mobile UI.
         */
        public bool $forbidden = false,
    ) {}
}

readonly class TermMatch
{
    public function __construct(
        /** The matched terminology entry. */
        public TermEntry $entry,

        /** Character offset in the source text where the term starts. */
        public int $offset,

        /** Character length of the matched span. */
        public int $length,
    ) {}
}
```

**Why `recognize` takes plain text, not Segment:**

Term recognition is a text operation. Inline codes are irrelevant to whether "hard drive" is a known term. Passing plain text (which `Segment::getPlainText()` already provides) keeps the interface simple and avoids coupling terminology to the inline code model.

---

## QualityCheckInterface

A single QA check. Each check class implements this interface. A QA runner iterates over all registered checks.

```php
namespace CatFramework\Core\Contract;

use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\SegmentPair;

interface QualityCheckInterface
{
    /**
     * Run this check on one segment pair.
     *
     * @param SegmentPair $pair The pair to check.
     * @param string $sourceLanguage BCP 47 code (some checks are language-sensitive).
     * @param string $targetLanguage BCP 47 code.
     * @return QualityIssue[] Zero or more issues found. Empty = passed.
     */
    public function check(
        SegmentPair $pair,
        string $sourceLanguage,
        string $targetLanguage,
    ): array;

    /**
     * Unique identifier for this check. Used in QualityIssue::$checkId
     * and for enabling/disabling checks in configuration.
     * e.g., "tag_consistency", "number_mismatch", "double_space".
     */
    public function getId(): string;

    /**
     * Human-readable name for display in the UI.
     * e.g., "Tag Consistency", "Number Format Check".
     */
    public function getName(): string;
}
```

**Why one-pair-at-a-time, not batch:**

A check that needs cross-segment context (e.g., "this term was translated differently in segment 14 and segment 82") is a terminology consistency check, not a QA check. QA checks are per-pair. This keeps the interface trivial to implement. A QA runner can parallelize checks across pairs without worrying about shared state.

**Why no severity configuration on the interface:**

A check knows its own default severity (tag errors are ERROR, double spaces are INFO). If a project needs to override severity, that's configuration on the QA runner, not on the check interface. Keeping severity out of the contract means check implementations stay simple.

---

## MachineTranslationInterface

Adapter for external MT services (DeepL, Google, etc.).

```php
namespace CatFramework\Core\Contract;

use CatFramework\Core\Model\Segment;

interface MachineTranslationInterface
{
    /**
     * Translate a single segment.
     *
     * The adapter is responsible for handling InlineCodes:
     * - Convert codes to a format the MT API understands (XML tags,
     *   placeholders, or strip them)
     * - Parse the MT response and reconstruct codes in the output Segment
     * - If the MT API does not support tags, strip codes before sending
     *   and return a Segment with no codes (the editor shows a warning)
     *
     * @param Segment $source Source segment (may contain InlineCodes).
     * @param string $sourceLanguage BCP 47 code.
     * @param string $targetLanguage BCP 47 code.
     * @return Segment The machine-translated target segment.
     * @throws \CatFramework\Core\Exception\MtException On API failure.
     */
    public function translate(
        Segment $source,
        string $sourceLanguage,
        string $targetLanguage,
    ): Segment;

    /**
     * Translate multiple segments in one API call.
     *
     * Batch translation reduces HTTP round trips. Implementations that
     * don't support batching can loop over translate() internally.
     *
     * @param Segment[] $sources
     * @return Segment[] Same order as input.
     */
    public function translateBatch(
        array $sources,
        string $sourceLanguage,
        string $targetLanguage,
    ): array;

    /**
     * Identifier for this MT provider. Used in MatchResult metadata
     * so the translator knows where a suggestion came from.
     * e.g., "deepl", "google_v3", "custom_nmt".
     */
    public function getProviderId(): string;
}
```

**Tradeoff: Segment vs. plain string.**

`translate` accepts `Segment` (not `string`) because MT APIs like DeepL v2 support XML tag handling. An adapter for DeepL can convert InlineCodes to XML tags, send them to the API, and parse them back. An adapter for an API that doesn't support tags strips them and returns a plain Segment. The interface accommodates both without forcing the lowest common denominator.

**Why no `getSupportedLanguages()`:**

Language support changes as MT providers update their models. Caching the result is fragile. The practical pattern is: try to translate, handle the "unsupported language pair" exception. If a consumer needs to show a language picker, they can query the MT provider's API directly through the concrete class.

---

## Interface Method Count Summary

| Interface                     | Methods | Notes                              |
|-------------------------------|---------|-------------------------------------|
| FileFilterInterface           | 4       | supports, extract, rebuild, getSupportedExtensions |
| SegmentationEngineInterface   | 2       | segment, loadRules                 |
| TranslationMemoryInterface    | 4       | lookup, store, import, export      |
| TerminologyProviderInterface  | 4       | recognize, lookup, import, addEntry |
| QualityCheckInterface         | 3       | check, getId, getName              |
| MachineTranslationInterface   | 3       | translate, translateBatch, getProviderId |

All within the 3-7 method range. Total: 20 methods across 6 interfaces.
