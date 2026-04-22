# Core Data Models

All classes live in `catframework/core`. Namespace: `CatFramework\Core\Model` (and `CatFramework\Core\Enum` for enums).

No implementation logic is shown. Method bodies are omitted. Only the shape of the data and the signatures of essential accessor methods.

---

## Supporting Enums

```php
namespace CatFramework\Core\Enum;

enum InlineCodeType: string
{
    /** Opening half of a paired tag, e.g. <b>, <a href="..."> */
    case OPENING = 'opening';

    /** Closing half of a paired tag, e.g. </b>, </a> */
    case CLOSING = 'closing';

    /** Self-closing tag with no pair, e.g. <br/>, <img/> */
    case STANDALONE = 'standalone';
}

enum SegmentState: string
{
    /** No translation yet. */
    case INITIAL = 'initial';

    /** Translator has entered a translation. */
    case TRANSLATED = 'translated';

    /** Reviewer has approved the translation. */
    case REVIEWED = 'reviewed';

    /** Final, locked state. */
    case FINAL = 'final';
}

enum MatchType: string
{
    /** 100% match including inline code positions. */
    case EXACT = 'exact';

    /** 100% plain text match, but inline codes differ in position or type. */
    case EXACT_TEXT = 'exact_text';

    /** Partial text match (score < 1.0). */
    case FUZZY = 'fuzzy';

    /** Result from machine translation, not from TM. */
    case MT = 'mt';
}

enum QualitySeverity: string
{
    /** Must fix before delivery (e.g., missing tag). */
    case ERROR = 'error';

    /** Should review (e.g., number format mismatch). */
    case WARNING = 'warning';

    /** Suggestion, non-blocking (e.g., double space). */
    case INFO = 'info';
}
```

---

## InlineCode

Represents a single formatting tag embedded within translatable text. Filters produce these when extracting text from structured formats (DOCX runs, HTML tags, etc.).

```php
namespace CatFramework\Core\Model;

readonly class InlineCode
{
    public function __construct(
        /**
         * Pairs opening and closing codes. Two InlineCode objects with the
         * same ID and types OPENING/CLOSING are two halves of one tag pair.
         * Standalone codes have a unique ID with no pair.
         */
        public string $id,

        /**
         * Whether this is an opening, closing, or standalone tag.
         */
        public InlineCodeType $type,

        /**
         * Original markup content. Opaque to everything except the filter
         * that created it. For DOCX, this might be raw OOXML run properties.
         * For HTML, the literal tag string. Used to reconstruct the original
         * file when writing translations back.
         */
        public string $data,

        /**
         * Human-readable label for the translation editor UI.
         * e.g., "<b>", "</b>", "{1}", "<br/>".
         * Null means the UI should generate a generic numbered placeholder.
         */
        public ?string $displayText = null,

        /**
         * True if this code was created by segmentation splitting a spanning
         * tag. An isolated OPENING has no matching CLOSING in the same
         * Segment (its pair is in an adjacent segment). Used by:
         * - De-segmentation (merge) to re-join spanning tags before rebuild.
         * - XLIFF serializer to map to <it> elements (XLIFF 1.2).
         * Default false. Only the segmentation engine sets this to true.
         */
        public bool $isIsolated = false,
    ) {}
}
```

**Why these properties and not others:**

- `id` + `type` together solve the paired-tag problem. A translator can move `{1}` and `{/1}` around in the target text, and the system knows they belong together. This maps directly to XLIFF 1.2's `<bpt id="1">` / `<ept id="1">` and XLIFF 2.0's `<pc id="1">`.
- `data` is deliberately opaque. Each file filter stores whatever it needs to reconstruct the original formatting. This avoids building a universal "formatting model" (which would be a massive abstraction over OOXML, HTML, RTF, etc.). The filter that created it is the only code that reads `data`.
- `displayText` is optional because auto-generating `{1}`, `{2}` placeholders is often sufficient and avoids the filter having to care about UI concerns.
- `isIsolated` marks codes created by segmentation when a tag spans a sentence boundary. The segmenter closes the tag at the end of one segment and re-opens it at the start of the next, marking both synthetic codes as isolated. De-segmentation (merging segments back for file rebuild) uses this flag to detect and re-join spanning tags. Maps to XLIFF 1.2 `<it>` elements. See Deliverable 5, Risk #1 for full analysis.
- No `canReorder` or `canDelete` flags. These are QA-time concerns (a QA check can verify tag integrity), not data model concerns. Adding them here would couple the model to editor behavior.

---

## Segment

A single translatable text unit. Content is an ordered sequence of plain text strings interleaved with InlineCode objects.

```php
namespace CatFramework\Core\Model;

class Segment
{
    /**
     * @param string $id Unique within the BilingualDocument. Used to link
     *     source/target segments and to reference segments in QA results.
     * @param array<string|InlineCode> $elements Ordered content. Example:
     *     ['Hello ', InlineCode(bold_open), 'world', InlineCode(bold_close), '!']
     *     Consecutive strings are allowed but discouraged (merge them).
     *     Empty array = empty segment.
     */
    public function __construct(
        public readonly string $id,
        private array $elements = [],
    ) {}

    /** Returns content as ordered array of strings and InlineCodes. */
    public function getElements(): array;

    /** Replaces all content elements. Used when translator edits the segment. */
    public function setElements(array $elements): void;

    /**
     * Returns concatenated text content with all InlineCodes stripped.
     * Used for TM matching, terminology lookup, word count, and display.
     * Example: "Hello world!" (from the elements example above).
     */
    public function getPlainText(): string;

    /** True if the segment contains no text (empty strings don't count). */
    public function isEmpty(): bool;

    /** Returns the InlineCode objects in order, without text. */
    public function getInlineCodes(): array;
}
```

**Why interleaved array instead of text + offset positions:**

Offsets break when the translator edits text (every insertion/deletion shifts all subsequent offsets). The interleaved array keeps codes anchored to their position between text fragments regardless of edits. This is how Okapi Framework's `TextFragment` works, and it is the natural representation for rendering a segment in a translation editor.

**Why Segment is mutable:**

The target Segment must be editable (translator types translations). The source Segment is effectively immutable after the filter creates it, but enforcing that distinction at the type level (two separate classes) adds complexity without real benefit. The BilingualDocument/SegmentPair structure makes the intent clear.

---

## SegmentPair

Links a source segment to its translation. The fundamental unit of work in a CAT tool.

```php
namespace CatFramework\Core\Model;

class SegmentPair
{
    public function __construct(
        /**
         * Original text extracted by the file filter. Should not be modified
         * after creation (no enforcement, but modifying it breaks file rebuild).
         */
        public readonly Segment $source,

        /**
         * Translation. Null = untranslated. Created empty when the document
         * is first opened, populated as the translator works.
         */
        public ?Segment $target = null,

        /**
         * Workflow state. Tracks where this pair is in the translation process.
         */
        public SegmentState $state = SegmentState::INITIAL,

        /**
         * Locked pairs should not be edited. Used for: pre-translated segments
         * from TM (100% matches auto-locked), segments the PM marks as final,
         * or non-translatable content the filter decided to expose.
         */
        public bool $isLocked = false,

        /**
         * Filter-specific reconstruction data. The filter stores whatever it
         * needs to put this segment back into the correct location in the
         * original file. For DOCX: paragraph index, run indices, bookmark
         * references. For HTML: DOM path. Opaque to everything else.
         */
        public readonly array $context = [],
    ) {}
}
```

**Why `context` is on SegmentPair, not on Segment:**

Reconstruction context is about "where in the file does this pair live," not about the text content. Putting it on Segment would mean duplicating it for source and target, or putting it only on source (arbitrary).

---

## BilingualDocument

The central data structure. A file filter produces one of these from a source file; the translator works on it; the filter consumes it to rebuild the translated file.

```php
namespace CatFramework\Core\Model;

class BilingualDocument
{
    /**
     * @param string $sourceLanguage BCP 47 tag, e.g. "en-US", "hi-IN", "ur-PK".
     * @param string $targetLanguage BCP 47 tag.
     * @param string $originalFile Filename of the source document (e.g., "manual.docx").
     *     Not a full path — just the name, for display and re-export.
     * @param string $mimeType MIME type of the original (e.g., "application/vnd.openxmlformats-officedocument.wordprocessingml.document").
     *     Used to select the correct filter for file rebuild.
     * @param SegmentPair[] $segmentPairs Ordered segment pairs.
     * @param array $skeleton Filter-specific data needed to rebuild the file.
     *     For DOCX: the original ZIP contents minus translatable text.
     *     For HTML: the original DOM with placeholder tokens.
     *     Opaque to everything except the filter.
     */
    public function __construct(
        public readonly string $sourceLanguage,
        public readonly string $targetLanguage,
        public readonly string $originalFile,
        public readonly string $mimeType,
        private array $segmentPairs = [],
        public readonly array $skeleton = [],
    ) {}

    /** @return SegmentPair[] */
    public function getSegmentPairs(): array;

    public function addSegmentPair(SegmentPair $pair): void;

    /** Lookup by source segment ID. Returns null if not found. */
    public function getSegmentPairById(string $sourceSegmentId): ?SegmentPair;

    /** Total number of segment pairs. */
    public function count(): int;
}
```

**Why `skeleton` is on the document, not serialized separately:**

The skeleton and the segment pairs are a matched set. If you serialize/transmit one without the other, you cannot rebuild the file. Keeping them together in one object prevents that mistake. The tradeoff is that BilingualDocument can be large (a DOCX skeleton is the entire ZIP minus text), but this is a runtime object, not something stored in a database row.

**Why no `getProgress()` method:**

Progress calculation (counting states across pairs) is trivial and better left to the consumer or a utility function. Putting it on the document creates a coupling to SegmentState semantics that might differ between workflows.

---

## TranslationUnit

A single entry in a translation memory. Represents one source-target pair with metadata. Maps to a TMX `<tu>` element.

```php
namespace CatFramework\Core\Model;

readonly class TranslationUnit
{
    public function __construct(
        /**
         * Source content. Stored as a Segment (with InlineCodes) so that
         * exact matching can compare code positions, not just plain text.
         */
        public Segment $source,

        /**
         * Target content (the translation).
         */
        public Segment $target,

        /** BCP 47 source language. */
        public string $sourceLanguage,

        /** BCP 47 target language. */
        public string $targetLanguage,

        /**
         * When this TU was created or imported. Used for "most recent wins"
         * conflict resolution when importing TMX files with duplicates.
         */
        public \DateTimeImmutable $createdAt,

        /**
         * Last time this TU was returned as a match result. Used for
         * TM maintenance: entries not used in years can be pruned.
         * Null = never used since import.
         */
        public ?\DateTimeImmutable $lastUsedAt = null,

        /**
         * Creator identifier (translator name, email, or system ID).
         * Imported from TMX <prop type="x-createdBy"> or set on creation.
         */
        public ?string $createdBy = null,

        /**
         * Arbitrary key-value metadata. Common keys: "project", "client",
         * "domain", "note". Maps to TMX <prop> and <note> elements.
         * Allows filtering TM lookups by project or domain.
         */
        public array $metadata = [],
    ) {}
}
```

**Why source and target are Segments, not plain strings:**

A TM entry created from `"Click {1}OK{/1}"` should remember that "OK" was inside a tag pair. When this TU matches a new segment `"Click {1}Save{/1}"`, the system can verify the tag structure matches and correctly place codes in the target. If TUs stored only plain text, this information would be lost and exact matching would produce false positives (matching segments with different tag structures).

**Why readonly:**

A TranslationUnit is a historical record. It should not be mutated in place. To update `lastUsedAt`, the TM engine creates a new instance (or handles it at the storage layer). This prevents accidental corruption of TM data in memory.

---

## MatchResult

Returned by TranslationMemoryInterface::lookup(). One per match candidate.

```php
namespace CatFramework\Core\Model;

readonly class MatchResult
{
    public function __construct(
        /** The matched TU from the TM. */
        public TranslationUnit $translationUnit,

        /**
         * Match score from 0.0 to 1.0.
         * 1.0 = exact match (identical source text and code structure).
         * 0.7+ = typically useful fuzzy match.
         * Scoring algorithm is the TM engine's concern, not the model's.
         */
        public float $score,

        /** Classification of the match. */
        public MatchType $type,

        /**
         * Identifier of the TM this match came from. Relevant when querying
         * multiple TMs (e.g., project TM + master TM). Null = single-TM mode.
         */
        public ?string $memoryId = null,
    ) {}
}
```

**Why `memoryId`:**

Professional CAT tools (Trados, memoQ) support cascaded TM lookups: check the project TM first, then a master TM, then a client TM. Each match result needs to say where it came from so the translator can assess trust level. Without this, multi-TM is impossible to implement cleanly later.

---

## QualityIssue

Returned by QualityCheckInterface::check(). One per problem found.

```php
namespace CatFramework\Core\Model;

readonly class QualityIssue
{
    public function __construct(
        /**
         * Identifier of the check that found this issue.
         * e.g., "tag_consistency", "number_mismatch", "empty_translation",
         * "leading_trailing_whitespace". Used for filtering and suppression.
         */
        public string $checkId,

        /** Severity. ERROR = must fix, WARNING = should review, INFO = suggestion. */
        public QualitySeverity $severity,

        /**
         * Human-readable description. Should be specific enough to act on.
         * Bad: "Tag error." Good: "Opening tag {1} in source has no match in target."
         */
        public string $message,

        /** Source segment ID of the pair this issue relates to. */
        public string $segmentId,

        /**
         * Character offset in the TARGET segment's plain text where the
         * issue starts. Null if the issue is about the whole segment
         * (e.g., empty translation). Used for highlighting in the editor.
         */
        public ?int $offset = null,

        /**
         * Length of the problematic span in the target text.
         * Null = point issue (no span), or whole-segment issue.
         */
        public ?int $length = null,
    ) {}
}
```

**Why offsets reference target plain text, not elements array:**

The editor displays plain text to the translator (codes are rendered as visual placeholders). Offsets into plain text map directly to cursor positions in the editor. Offsets into the elements array would require the editor to do index-to-position translation, which is error-prone.

---

## Summary of Mutability

| Model              | Mutable? | Why                                                     |
|---------------------|----------|---------------------------------------------------------|
| InlineCode          | No       | Value object. Tags don't change after extraction.       |
| Segment             | Yes      | Target segments are edited by the translator.           |
| SegmentPair         | Yes      | Target, state, and lock status change during work.      |
| BilingualDocument   | Yes      | Pairs are added during extraction and modified during translation. |
| TranslationUnit     | No       | Historical TM record. Updates create new instances.     |
| MatchResult         | No       | Query result. Immutable snapshot.                       |
| QualityIssue        | No       | Check result. Immutable snapshot.                       |
