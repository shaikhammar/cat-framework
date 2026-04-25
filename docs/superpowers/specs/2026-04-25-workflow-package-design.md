# Design: catframework/workflow (Phase 4, Track A2)

**Date:** 2026-04-25
**Status:** Approved

---

## Overview

`catframework/workflow` eliminates the boilerplate of wiring five packages together for every new translation pipeline consumer. It provides:

1. `FileFilterRegistry` — registers and selects `FileFilterInterface` implementations
2. `WorkflowRunner` — orchestrates the full pipeline: filter → segment → TM → terminology → MT → QA → XLIFF
3. `WorkflowResult`, `TmMatchStats`, `WorkflowOptions` — value objects for pipeline I/O
4. `ProjectWorkflowBuilder` — hydrates a `WorkflowRunner` from a `ProjectManifest` (bridge between `catframework/project` and this package)
5. `WorkflowRunnerInterface` — contract for consumers who want to swap the orchestrator

The dependency arrow: `catframework/workflow` depends on `catframework/project`. The reverse is false — `project` has no knowledge of `workflow`.

---

## Package structure

```
packages/workflow/
├── composer.json
├── phpunit.xml
├── src/
│   ├── Exception/
│   │   └── WorkflowException.php
│   ├── FileFilterRegistry.php
│   ├── TmMatchStats.php
│   ├── WorkflowOptions.php
│   ├── WorkflowResult.php
│   ├── WorkflowRunnerInterface.php
│   ├── WorkflowRunner.php
│   └── ProjectWorkflowBuilder.php
└── tests/
    ├── FileFilterRegistryTest.php
    ├── WorkflowRunnerTest.php
    └── ProjectWorkflowBuilderTest.php
```

---

## composer.json dependencies

```json
{
    "require": {
        "php": "^8.2",
        "ext-zip": "*",
        "catframework/core": "^0.1",
        "catframework/project": "^0.1",
        "catframework/segmentation": "^0.1",
        "catframework/translation-memory": "^0.1",
        "catframework/mt": "^0.1",
        "catframework/qa": "^0.1",
        "catframework/terminology": "^0.1",
        "catframework/xliff": "^0.1"
    }
}
```

All path repositories point to `../core`, `../project`, `../segmentation`, etc.

---

## `FileFilterRegistry`

```php
final class FileFilterRegistry
{
    /** @var FileFilterInterface[] */
    private array $filters = [];

    public function register(FileFilterInterface $filter): void
    {
        $this->filters[] = $filter;
    }

    // Returns first filter where supports() returns true.
    // Throws WorkflowException if none match.
    public function getFilter(string $filePath, ?string $mimeType = null): FileFilterInterface;
}
```

- Iterates registered filters in registration order; first `supports()` win.
- Throws `WorkflowException("No filter found for: {$filePath}")` if nothing matches.
- Consumers register filters explicitly — no auto-discovery.

---

## `WorkflowRunnerInterface`

```php
interface WorkflowRunnerInterface
{
    public function process(string $filePath, string $targetLang): WorkflowResult;
}
```

---

## `WorkflowRunner`

```php
final class WorkflowRunner implements WorkflowRunnerInterface
{
    public function __construct(
        private readonly FileFilterRegistry $fileFilterRegistry,
        private readonly SrxSegmentationEngine $segmentationEngine,
        private readonly XliffWriter $xliffWriter,
        private readonly string $sourceLang,                          // BCP 47, e.g. "en-US"
        private readonly ?TranslationMemoryInterface $translationMemory = null,
        private readonly ?TerminologyProviderInterface $terminologyProvider = null,
        private readonly ?MachineTranslationInterface $mtAdapter = null,
        private readonly ?QualityRunner $qaRunner = null,
        private readonly WorkflowOptions $options = new WorkflowOptions(),
    ) {}

    // Register a progress callback. Called after each segment pair is processed.
    public function onSegmentProcessed(callable $cb): void;

    public function process(string $filePath, string $targetLang): WorkflowResult;
}
```

`WorkflowRunner` requires only `fileFilterRegistry`, `segmentationEngine`, and `xliffWriter`. All other dependencies are nullable — a runner with just those three performs pure extraction with no TM/MT/QA.

**Progress callback signature:**

```php
function (SegmentPair $pair, int $index, int $total): void
```

Called post-processing (after TM/MT applied) so caller can read match scores or state. Single callback; no event system.

---

## Value objects

### `WorkflowOptions`

```php
final class WorkflowOptions
{
    public float $mtFillThreshold = 0.0;       // MT fills if best TM score < this value
    public float $autoConfirmThreshold = 1.0;  // auto-confirm + lock if TM score >= this
    public string $outputDir = '';             // empty = same directory as source file
    public bool $writeXliff = true;

    public static function defaults(): self { return new self(); }
}
```

### `TmMatchStats`

```php
final class TmMatchStats
{
    public function __construct(
        public readonly int $exact,      // score == 1.0
        public readonly int $fuzzy,      // score >= minScore but < 1.0
        public readonly int $mt,         // filled by MT adapter
        public readonly int $unmatched,  // no TM match, no MT
    ) {}
}
```

### `WorkflowResult`

```php
final class WorkflowResult
{
    public function __construct(
        public readonly BilingualDocument $document,
        /** @var QualityIssue[] */
        public readonly array $qaIssues,
        public readonly TmMatchStats $matchStats,
        public readonly ?string $xliffPath,   // null if writeXliff = false
        public readonly array $timings,       // ['extract' => 0.12, 'segment' => 0.03, ...]
    ) {}
}
```

`QAIssueCollection` (from D23) is not implemented — `QualityRunner::run()` already returns `QualityIssue[]`, so a wrapper adds nothing.

---

## Pipeline steps

Inside `WorkflowRunner::process(string $filePath, string $targetLang)`:

```
1. $filter = FileFilterRegistry::getFilter($filePath)
2. $doc    = $filter->extract($filePath, $sourceLang, $targetLang)  → BilingualDocument
   ($sourceLang comes from WorkflowRunner constructor or ProjectManifest)
3. For each SegmentPair $pair (index $i of $total):
   a. $sentences = SrxSegmentationEngine::segment($pair->source, $sourceLang)
      If >1 sentence: replace $pair with first sentence pair; insert remaining as new pairs after current position
   b. TM lookup (if $translationMemory set):
      - best score >= $autoConfirmThreshold → set target, state=TRANSLATED, isLocked=true  (exact)
      - best score >= minScore (0.7) but < $autoConfirmThreshold → set target, state=TRANSLATED  (fuzzy)
      - Track outcome in matchStats counter
   c. TerminologyProvider::recognize($pair->source->getPlainText(), ...) if set
      → store TermMatch[] in $pair->context['termMatches']
   d. If no TM match AND best score < $mtFillThreshold AND $mtAdapter set:
      → MtAdapter::translate($pair->source, $sourceLang, $targetLang) → set $pair->target, state=TRANSLATED
      → increment matchStats.mt
   e. Fire $onSegmentProcessed($pair, $index, $total)
4. If $qaRunner set: $qaIssues = $qaRunner->run($doc)
   If any issue severity >= $options->qaFailOnSeverity: throw WorkflowException
5. If $options->writeXliff:
   $xliffPath = resolve output path from $options->outputDir + basename($filePath) + '.xlf'
   XliffWriter::write($doc, $xliffPath)
6. Return WorkflowResult($doc, $qaIssues, $matchStats, $xliffPath, $timings)
```

**Timing:** Each step is wrapped with `microtime(true)` before/after. `$timings` keys: `'extract'`, `'segment'`, `'tm'`, `'terminology'`, `'mt'`, `'qa'`, `'xliff'`.

---

## `ProjectWorkflowBuilder`

Lives in `catframework/workflow`. Bridges `ProjectManifest` → `WorkflowRunner`. Keeps `catframework/project` free of workflow-package dependencies.

```php
final class ProjectWorkflowBuilder
{
    public function __construct(private readonly ProjectManifest $manifest) {}

    // Hydrates SqliteTranslationMemory, SqliteTerminologyProvider, MtAdapter, QualityRunner
    // from manifest paths and config. Consumer supplies the FileFilterRegistry.
    public function build(string $targetLang, FileFilterRegistry $registry): WorkflowRunner;
}
```

**Hydration logic in `build()`:**

- `tm`: for each `TmConfig`, construct `SqliteTranslationMemory` from resolved path. If multiple TMs, use the first writable one as the primary (lookup against all — out of scope for A2; use first for now).
- `glossaries`: construct `SqliteTerminologyProvider` from resolved path.
- `mt`: if `$manifest->mt` is set, resolve adapter name → class map (`'deepl' => DeepLAdapter::class`, `'google' => GoogleTranslateAdapter::class`). Throw `WorkflowException` for unknown adapter names. HTTP client injection: use `GuzzleHttp\Client` if available, else throw `WorkflowException("HTTP client required for MT — install guzzlehttp/guzzle")`.
- `qa`: construct `QualityRunner` and register checks listed in `$manifest->qa->checks` by class name lookup. Throw `WorkflowException` for unknown check names.
- Resolve `$sourceLang` from `$manifest->sourceLang`. Pass to `WorkflowRunner` as a constructor parameter (add `private readonly string $sourceLang` to `WorkflowRunner`).

---

## Error handling

| Exception | Thrown by | Cause |
|---|---|---|
| `WorkflowException` | `FileFilterRegistry::getFilter()` | No filter matches file |
| `WorkflowException` | `WorkflowRunner::process()` | QA severity threshold breached |
| `WorkflowException` | `ProjectWorkflowBuilder::build()` | Unknown MT adapter name, unknown QA check name, missing HTTP client |
| `FilterException`, `MtException`, `TmException` | Propagate unwrapped | Pipeline step failure — caller handles |

---

## Testing strategy

**`FileFilterRegistryTest`** — unit:
- Register two stub filters; assert correct one selected by extension
- Assert `WorkflowException` when no filter matches

**`WorkflowRunnerTest`** — unit with test doubles (anonymous classes / simple stubs):
- TM match at/above `autoConfirmThreshold` → segment locked, state=TRANSLATED
- TM match below threshold → segment translated, not locked
- MT fill triggered when score < `mtFillThreshold`
- MT skipped when score >= `mtFillThreshold`
- `matchStats` counts correct after processing
- QA `WorkflowException` thrown when severity breached
- Progress callback fires with correct `$index` and `$total`
- `$timings` keys present in result

**`ProjectWorkflowBuilderTest`** — integration (real temp files):
- Valid manifest with plaintext filter → `build()` returns `WorkflowRunner` without throwing
- Unknown MT adapter name → `WorkflowException`
- Unknown QA check name → `WorkflowException`

**Not tested in A2:**
- Full end-to-end with real DOCX/XLIFF
- HTTP calls to real MT APIs

---

## What is NOT in this package

| Item | Where it lives |
|---|---|
| Filter implementations (DocxFilter, etc.) | `catframework/filter-*` packages |
| HTTP client for MT | Consumer's composer.json (guzzlehttp/guzzle) |
| Multi-TM round-robin lookup | Out of scope — use first TM for A2 |
| PSR-14 event dispatcher | Out of scope — single callback covers 95% of cases |
| `cat-framework-api` | Track B (Phase 4) |
