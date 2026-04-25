# catframework/workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create `catframework/workflow` — the pipeline orchestration package that wires file filtering, segmentation, TM, terminology, MT, QA, and XLIFF writing into a single `WorkflowRunner::process()` call.

**Architecture:** `FileFilterRegistry` selects the right filter by extension. `WorkflowRunner` runs the full pipeline (extract → segment → TM → terminology → MT → QA → XLIFF), injecting all dependencies at construction. `ProjectWorkflowBuilder` bridges `catframework/project`'s `ProjectManifest` into a fully-hydrated `WorkflowRunner`, keeping `catframework/project` free of workflow dependencies.

**Tech Stack:** PHP 8.2+, PHPUnit 11, catframework/core, catframework/project, catframework/segmentation, catframework/translation-memory, catframework/mt, catframework/qa, catframework/terminology, catframework/xliff.

**PHP binary:** `C:\Users\shaik\.config\herd\bin\php83\php.exe`
**Composer:** Run as `php composer.phar` or however Composer is available on PATH from inside the package directory.

---

## File Map

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

**Key interface signatures used from other packages** (do not re-declare):
- `FileFilterInterface::supports(string $filePath, ?string $mimeType = null): bool`
- `FileFilterInterface::extract(string $filePath, string $sourceLanguage, string $targetLanguage): BilingualDocument`
- `TranslationMemoryInterface::lookup(Segment $source, string $sourceLanguage, string $targetLanguage, float $minScore = 0.7, int $maxResults = 5): MatchResult[]`
- `TranslationMemoryInterface::store(TranslationUnit $unit): void`
- `TranslationMemoryInterface::import(string $tmxFilePath): int`
- `TranslationMemoryInterface::export(string $tmxFilePath): int`
- `MachineTranslationInterface::translate(Segment $source, string $sourceLanguage, string $targetLanguage): Segment`
- `MachineTranslationInterface::translateBatch(array $sources, string $sourceLanguage, string $targetLanguage): array`
- `MachineTranslationInterface::getProviderId(): string`
- `TerminologyProviderInterface::recognize(string $sourceText, string $sourceLanguage, string $targetLanguage): TermMatch[]`
- `TerminologyProviderInterface::lookup(string $term, string $sourceLanguage, string $targetLanguage): TermEntry[]`
- `TerminologyProviderInterface::import(string $tbxFilePath): int`
- `TerminologyProviderInterface::addEntry(TermEntry $entry): void`
- `QualityRunner::register(QualityCheckInterface $check): void`
- `QualityRunner::registerDocumentCheck(DocumentQualityCheckInterface $check): void`
- `QualityRunner::run(BilingualDocument $document): QualityIssue[]` (per-pair checks only)
- `SrxSegmentationEngine::segment(Segment $input, string $languageCode): Segment[]`
- `XliffWriter::write(BilingualDocument $doc, string $xliffPath): void`
- `MatchResult::$score: float`, `MatchResult::$type: MatchType`, `MatchResult::$translationUnit: TranslationUnit`
- `TranslationUnit::$source: Segment`, `TranslationUnit::$target: Segment`
- `QualityIssue::$severity: QualitySeverity`, `QualityIssue::$checkId: string`, `QualityIssue::$message: string`
- `QualitySeverity::ERROR = 'error'`, `QualitySeverity::WARNING = 'warning'`, `QualitySeverity::INFO = 'info'`
- `SegmentState::INITIAL`, `SegmentState::TRANSLATED`
- `BilingualDocument::__construct(string $sourceLanguage, string $targetLanguage, string $originalFile, string $mimeType, array $segmentPairs = [], array $skeleton = [])`
- `Segment::__construct(string $id, array $elements = [])`
- `SegmentPair::__construct(Segment $source, ?Segment $target = null, SegmentState $state = SegmentState::INITIAL, bool $isLocked = false, array $context = [])`
- `SegmentPair::$target` (mutable), `SegmentPair::$state` (mutable), `SegmentPair::$isLocked` (mutable)
- `SegmentPair::$context` is `readonly` — terminology results CANNOT be stored here

**Key QA check classes (in `CatFramework\Qa\Check` namespace):**
- Per-pair (`QualityCheckInterface`): `EmptyTranslationCheck`, `TagConsistencyCheck`, `NumberConsistencyCheck`, `WhitespaceCheck`, `DoubleSpaceCheck`
- Per-pair with dependency: `TerminologyConsistencyCheck(private readonly ?TerminologyProviderInterface $provider = null)`
- Document-level (`DocumentQualityCheckInterface`): `SegmentConsistencyCheck`

**MT adapter constructors:**
- `DeepLAdapter(ClientInterface $httpClient, RequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory, string $apiKey)`
- `GoogleTranslateAdapter(ClientInterface $httpClient, RequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory, string $apiKey, string $projectId)` — requires projectId not in manifest; throw `WorkflowException` with helpful message

**`SqliteTranslationMemory` constructor:** `__construct(PDO $pdo)` — create a `PDO('sqlite:<path>')` to wrap it
**`SqliteTerminologyProvider` constructor:** `__construct(string $databasePath)` — path to SQLite file

---

## Task 1: Scaffold

**Files:**
- Create: `packages/workflow/composer.json`
- Create: `packages/workflow/phpunit.xml`
- Create directories: `packages/workflow/src/Exception/`, `packages/workflow/tests/`

- [ ] **Step 1: Create directory structure**

```bash
mkdir -p packages/workflow/src/Exception packages/workflow/tests
```

- [ ] **Step 2: Create `packages/workflow/composer.json`**

```json
{
    "name": "catframework/workflow",
    "description": "Pipeline orchestration for the CAT Framework — wires filter, segmentation, TM, MT, QA, and XLIFF into a single WorkflowRunner",
    "type": "library",
    "license": "MIT",
    "version": "0.1.0",
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
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "catframework/filter-plaintext": "^0.1"
    },
    "autoload": {
        "psr-4": {
            "CatFramework\\Workflow\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "CatFramework\\Workflow\\Tests\\": "tests/"
        }
    },
    "repositories": [
        {"type": "path", "url": "../core"},
        {"type": "path", "url": "../project"},
        {"type": "path", "url": "../segmentation"},
        {"type": "path", "url": "../translation-memory"},
        {"type": "path", "url": "../mt"},
        {"type": "path", "url": "../qa"},
        {"type": "path", "url": "../terminology"},
        {"type": "path", "url": "../xliff"},
        {"type": "path", "url": "../filter-plaintext"}
    ],
    "config": {
        "sort-packages": true
    }
}
```

- [ ] **Step 3: Create `packages/workflow/phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="workflow">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 4: Run Composer install**

```bash
cd packages/workflow
composer install
```

Expected: dependencies resolve and `vendor/` is created. If SSL errors, disable Avast temporarily before running.

- [ ] **Step 5: Commit scaffold**

```bash
git add packages/workflow/composer.json packages/workflow/phpunit.xml
git commit -m "feat(workflow): scaffold catframework/workflow package"
```

---

## Task 2: Value Objects and Exception

**Files:**
- Create: `packages/workflow/src/Exception/WorkflowException.php`
- Create: `packages/workflow/src/WorkflowOptions.php`
- Create: `packages/workflow/src/TmMatchStats.php`
- Create: `packages/workflow/src/WorkflowResult.php`

These are pure value objects with no external dependencies — no tests needed beyond type-checking by PHPUnit in later tasks. There is no meaningful logic to unit-test here.

- [ ] **Step 1: Create `src/Exception/WorkflowException.php`**

```php
<?php

declare(strict_types=1);

namespace CatFramework\Workflow\Exception;

final class WorkflowException extends \RuntimeException {}
```

- [ ] **Step 2: Create `src/WorkflowOptions.php`**

```php
<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

final class WorkflowOptions
{
    /**
     * MT fills a segment if the best TM score is strictly less than this value.
     * 0.0 = MT never fills (default — opt-in).
     * 1.0 = MT fills every segment that has no exact TM match.
     * 0.75 = MT fills when best TM score is below 75%.
     */
    public float $mtFillThreshold = 0.0;

    /**
     * Auto-confirm and lock a segment if TM score is >= this value.
     * 1.0 = only exact matches are auto-confirmed (default).
     */
    public float $autoConfirmThreshold = 1.0;

    /**
     * Directory for XLIFF output. Empty string = same directory as source file.
     */
    public string $outputDir = '';

    /** Write XLIFF file. false = dry-run mode (no files written). */
    public bool $writeXliff = true;

    /**
     * Throw WorkflowException if any QA issue has severity >= this value.
     * null = never throw. Values: 'error' | 'warning' | 'info'.
     */
    public ?string $qaFailOnSeverity = null;

    public static function defaults(): self
    {
        return new self();
    }
}
```

- [ ] **Step 3: Create `src/TmMatchStats.php`**

```php
<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

final class TmMatchStats
{
    public function __construct(
        public readonly int $exact,      // TM score >= autoConfirmThreshold
        public readonly int $fuzzy,      // TM score >= 0.7 but < autoConfirmThreshold
        public readonly int $mt,         // filled by MT adapter
        public readonly int $unmatched,  // no TM match and no MT fill
    ) {}
}
```

- [ ] **Step 4: Create `src/WorkflowResult.php`**

```php
<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\QualityIssue;

final class WorkflowResult
{
    public function __construct(
        public readonly BilingualDocument $document,
        /** @var QualityIssue[] */
        public readonly array $qaIssues,
        public readonly TmMatchStats $matchStats,
        public readonly ?string $xliffPath,   // null when writeXliff = false
        public readonly array $timings,       // keys: 'extract','segment','tm','terminology','mt','qa','xliff'
    ) {}
}
```

- [ ] **Step 5: Commit**

```bash
git add packages/workflow/src/
git commit -m "feat(workflow): add WorkflowException, WorkflowOptions, TmMatchStats, WorkflowResult"
```

---

## Task 3: WorkflowRunnerInterface + FileFilterRegistry

**Files:**
- Create: `packages/workflow/src/WorkflowRunnerInterface.php`
- Create: `packages/workflow/src/FileFilterRegistry.php`
- Create: `packages/workflow/tests/FileFilterRegistryTest.php`

- [ ] **Step 1: Write the failing tests**

Create `packages/workflow/tests/FileFilterRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Workflow\Tests;

use CatFramework\Core\Contract\FileFilterInterface;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Workflow\Exception\WorkflowException;
use CatFramework\Workflow\FileFilterRegistry;
use PHPUnit\Framework\TestCase;

final class FileFilterRegistryTest extends TestCase
{
    private function makeFilter(string $extension): FileFilterInterface
    {
        return new class($extension) implements FileFilterInterface {
            public function __construct(private readonly string $ext) {}

            public function supports(string $filePath, ?string $mimeType = null): bool
            {
                return str_ends_with(strtolower($filePath), $this->ext);
            }

            public function extract(string $filePath, string $sourceLanguage, string $targetLanguage): BilingualDocument
            {
                return new BilingualDocument($sourceLanguage, $targetLanguage, basename($filePath), 'text/plain');
            }

            public function rebuild(BilingualDocument $document, string $outputPath): void {}

            public function getSupportedExtensions(): array
            {
                return [$this->ext];
            }
        };
    }

    public function test_returns_first_matching_filter(): void
    {
        $registry = new FileFilterRegistry();
        $txtFilter  = $this->makeFilter('.txt');
        $docxFilter = $this->makeFilter('.docx');

        $registry->register($txtFilter);
        $registry->register($docxFilter);

        $this->assertSame($txtFilter, $registry->getFilter('/path/to/file.txt'));
    }

    public function test_selects_by_extension(): void
    {
        $registry = new FileFilterRegistry();
        $txtFilter  = $this->makeFilter('.txt');
        $docxFilter = $this->makeFilter('.docx');

        $registry->register($txtFilter);
        $registry->register($docxFilter);

        $this->assertSame($docxFilter, $registry->getFilter('/path/to/document.docx'));
    }

    public function test_throws_when_no_filter_matches(): void
    {
        $registry = new FileFilterRegistry();
        $registry->register($this->makeFilter('.txt'));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No filter found for:');

        $registry->getFilter('/path/to/file.pdf');
    }

    public function test_throws_for_empty_registry(): void
    {
        $this->expectException(WorkflowException::class);
        (new FileFilterRegistry())->getFilter('/any/file.txt');
    }
}
```

- [ ] **Step 2: Run tests — expect failure (class not found)**

```bash
cd packages/workflow
vendor/bin/phpunit tests/FileFilterRegistryTest.php
```

Expected: error about `FileFilterRegistry` class not found.

- [ ] **Step 3: Create `src/WorkflowRunnerInterface.php`**

```php
<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

interface WorkflowRunnerInterface
{
    public function process(string $filePath, string $targetLang): WorkflowResult;
}
```

- [ ] **Step 4: Create `src/FileFilterRegistry.php`**

```php
<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

use CatFramework\Core\Contract\FileFilterInterface;
use CatFramework\Workflow\Exception\WorkflowException;

final class FileFilterRegistry
{
    /** @var FileFilterInterface[] */
    private array $filters = [];

    public function register(FileFilterInterface $filter): void
    {
        $this->filters[] = $filter;
    }

    public function getFilter(string $filePath, ?string $mimeType = null): FileFilterInterface
    {
        foreach ($this->filters as $filter) {
            if ($filter->supports($filePath, $mimeType)) {
                return $filter;
            }
        }

        throw new WorkflowException("No filter found for: {$filePath}");
    }
}
```

- [ ] **Step 5: Run tests — expect all 4 passing**

```bash
vendor/bin/phpunit tests/FileFilterRegistryTest.php
```

Expected: `4 / 4 tests OK`

- [ ] **Step 6: Commit**

```bash
git add packages/workflow/src/WorkflowRunnerInterface.php packages/workflow/src/FileFilterRegistry.php packages/workflow/tests/FileFilterRegistryTest.php
git commit -m "feat(workflow): add WorkflowRunnerInterface and FileFilterRegistry"
```

---

## Task 4: WorkflowRunner

**Files:**
- Create: `packages/workflow/src/WorkflowRunner.php`
- Create: `packages/workflow/tests/WorkflowRunnerTest.php`

**Pipeline logic summary:**
1. `FileFilterRegistry::getFilter($filePath)` → `FileFilterInterface`
2. `extract($filePath, $sourceLang, $targetLang)` → `BilingualDocument` with raw pairs
3. Segmentation pass: for each pair call `SrxSegmentationEngine::segment()`. If >1 sentences, split the pair into N new pairs. Rebuild `BilingualDocument` with expanded pairs.
4. For each expanded pair: TM lookup → terminology (timed only, not stored) → MT fill → fire progress callback.
5. If `$qaRunner` set: call `$qaRunner->run($doc)` (per-pair checks). Check QA threshold and throw `WorkflowException` if breached.
6. If `writeXliff`: resolve output path and call `XliffWriter::write()`.
7. Return `WorkflowResult`.

**TM scoring logic:**
- Returns matches sorted by score desc (already filtered to >= minScore=0.7 by the interface).
- `$best->score >= autoConfirmThreshold` → set target, state=TRANSLATED, isLocked=true, increment `$exact`.
- Otherwise → set target, state=TRANSLATED, increment `$fuzzy`.
- No matches returned → `$tmMatched = false`.

**MT fill condition:** `!$tmMatched && $tmBestScore < $mtFillThreshold && $mtAdapter !== null`
- Default `$mtFillThreshold = 0.0` → MT never fires by default (0.0 < 0.0 is false).
- Set to 1.0 to fill all unmatched; 0.75 to fill below-75% segments.

**QA severity threshold:**
- `null` → never throw
- Convert `$options->qaFailOnSeverity` string to `QualitySeverity` enum with `QualitySeverity::from()`
- Severity level: INFO=0, WARNING=1, ERROR=2. Throw if any issue's level >= threshold level.

**XLIFF path:** `{outputDir or dirname($filePath)}/{basename($filePath)}.xlf`

**Timings:** `'extract'`, `'segment'`, `'tm'` (total across all pairs), `'terminology'` (total), `'mt'` (total), `'qa'`, `'xliff'`.

**Progress callback signature:** `function(SegmentPair $pair, int $index, int $total): void` — called after TM/MT applied (so caller can read $pair->state, $pair->target). `$index` is 0-based.

- [ ] **Step 1: Write the failing tests**

Create `packages/workflow/tests/WorkflowRunnerTest.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Workflow\Tests;

use CatFramework\Core\Contract\FileFilterInterface;
use CatFramework\Core\Contract\MachineTranslationInterface;
use CatFramework\Core\Contract\TerminologyProviderInterface;
use CatFramework\Core\Contract\TranslationMemoryInterface;
use CatFramework\Core\Enum\MatchType;
use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Enum\SegmentState;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\MatchResult;
use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Core\Model\TranslationUnit;
use CatFramework\Qa\QualityRunner;
use CatFramework\Qa\Check\EmptyTranslationCheck;
use CatFramework\Segmentation\SrxSegmentationEngine;
use CatFramework\Workflow\Exception\WorkflowException;
use CatFramework\Workflow\FileFilterRegistry;
use CatFramework\Workflow\WorkflowOptions;
use CatFramework\Workflow\WorkflowRunner;
use CatFramework\Xliff\XliffWriter;
use PHPUnit\Framework\TestCase;

final class WorkflowRunnerTest extends TestCase
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeDoc(array $sourcePhrases): BilingualDocument
    {
        $pairs = [];
        foreach ($sourcePhrases as $i => $text) {
            $pairs[] = new SegmentPair(new Segment("s{$i}", [$text]));
        }
        return new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', $pairs);
    }

    private function makeFilter(BilingualDocument $doc): FileFilterInterface
    {
        return new class($doc) implements FileFilterInterface {
            public function __construct(private readonly BilingualDocument $doc) {}
            public function supports(string $filePath, ?string $mimeType = null): bool { return true; }
            public function extract(string $filePath, string $sourceLanguage, string $targetLanguage): BilingualDocument { return $this->doc; }
            public function rebuild(BilingualDocument $document, string $outputPath): void {}
            public function getSupportedExtensions(): array { return ['.txt']; }
        };
    }

    private function makeRegistry(FileFilterInterface $filter): FileFilterRegistry
    {
        $registry = new FileFilterRegistry();
        $registry->register($filter);
        return $registry;
    }

    private function makeRunner(
        FileFilterRegistry $registry,
        ?TranslationMemoryInterface $tm = null,
        ?MachineTranslationInterface $mt = null,
        ?QualityRunner $qa = null,
        ?WorkflowOptions $options = null,
    ): WorkflowRunner {
        $options ??= WorkflowOptions::defaults();
        $options->writeXliff = false;

        return new WorkflowRunner(
            fileFilterRegistry: $registry,
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: 'en-US',
            translationMemory: $tm,
            terminologyProvider: null,
            mtAdapter: $mt,
            qaRunner: $qa,
            options: $options,
        );
    }

    private function makeTm(float $score): TranslationMemoryInterface
    {
        return new class($score) implements TranslationMemoryInterface {
            public function __construct(private readonly float $score) {}

            public function lookup(Segment $source, string $sourceLanguage, string $targetLanguage, float $minScore = 0.7, int $maxResults = 5): array
            {
                if ($this->score < $minScore) {
                    return [];
                }
                return [new MatchResult(
                    translationUnit: new TranslationUnit(
                        source: $source,
                        target: new Segment('t-' . $source->id, ['[TM] ' . $source->getPlainText()]),
                        sourceLanguage: $sourceLanguage,
                        targetLanguage: $targetLanguage,
                        createdAt: new \DateTimeImmutable(),
                    ),
                    score: $this->score,
                    type: $this->score >= 1.0 ? MatchType::EXACT : MatchType::FUZZY,
                )];
            }

            public function store(TranslationUnit $unit): void {}
            public function import(string $tmxFilePath): int { return 0; }
            public function export(string $tmxFilePath): int { return 0; }
        };
    }

    private function makeMt(): MachineTranslationInterface
    {
        return new class implements MachineTranslationInterface {
            public function translate(Segment $source, string $sourceLanguage, string $targetLanguage): Segment
            {
                return new Segment('mt-' . $source->id, ['[MT] ' . $source->getPlainText()]);
            }

            public function translateBatch(array $sources, string $sourceLanguage, string $targetLanguage): array
            {
                return array_map(fn($s) => $this->translate($s, $sourceLanguage, $targetLanguage), $sources);
            }

            public function getProviderId(): string { return 'stub-mt'; }
        };
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_exact_tm_match_locks_segment(): void
    {
        $doc = $this->makeDoc(['Hello world']);
        $runner = $this->makeRunner(
            registry: $this->makeRegistry($this->makeFilter($doc)),
            tm: $this->makeTm(1.0),
        );

        $result = $runner->process('/tmp/test.txt', 'fr-FR');
        $pairs  = $result->document->getSegmentPairs();

        $this->assertSame(SegmentState::TRANSLATED, $pairs[0]->state);
        $this->assertTrue($pairs[0]->isLocked);
        $this->assertSame('[TM] Hello world', $pairs[0]->target->getPlainText());
    }

    public function test_fuzzy_tm_match_translates_but_does_not_lock(): void
    {
        $doc = $this->makeDoc(['Hello world']);
        $runner = $this->makeRunner(
            registry: $this->makeRegistry($this->makeFilter($doc)),
            tm: $this->makeTm(0.85),
        );

        $result = $runner->process('/tmp/test.txt', 'fr-FR');
        $pairs  = $result->document->getSegmentPairs();

        $this->assertSame(SegmentState::TRANSLATED, $pairs[0]->state);
        $this->assertFalse($pairs[0]->isLocked);
    }

    public function test_mt_fill_triggered_when_no_tm_match_and_threshold_exceeded(): void
    {
        $doc = $this->makeDoc(['Hello world']);

        // TM returns nothing (score 0.0 is below minScore 0.7)
        $options = WorkflowOptions::defaults();
        $options->mtFillThreshold = 1.0; // fill all unmatched
        $options->writeXliff = false;

        $runner = new WorkflowRunner(
            fileFilterRegistry: $this->makeRegistry($this->makeFilter($doc)),
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: 'en-US',
            translationMemory: $this->makeTm(0.0), // returns [] (below minScore)
            mtAdapter: $this->makeMt(),
            options: $options,
        );

        $result = $runner->process('/tmp/test.txt', 'fr-FR');
        $pairs  = $result->document->getSegmentPairs();

        $this->assertSame('[MT] Hello world', $pairs[0]->target->getPlainText());
        $this->assertSame(1, $result->matchStats->mt);
        $this->assertSame(0, $result->matchStats->unmatched);
    }

    public function test_mt_skipped_when_tm_score_meets_threshold(): void
    {
        $doc = $this->makeDoc(['Hello world']);

        $options = WorkflowOptions::defaults();
        $options->mtFillThreshold = 0.5; // only fill below 50%
        $options->writeXliff = false;

        // TM returns 0.85 match — above mtFillThreshold, so MT should NOT fire
        $runner = new WorkflowRunner(
            fileFilterRegistry: $this->makeRegistry($this->makeFilter($doc)),
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: 'en-US',
            translationMemory: $this->makeTm(0.85),
            mtAdapter: $this->makeMt(),
            options: $options,
        );

        $result = $runner->process('/tmp/test.txt', 'fr-FR');

        $this->assertSame(0, $result->matchStats->mt);
        // TM filled it, so it's a fuzzy match
        $this->assertSame(1, $result->matchStats->fuzzy);
        $this->assertStringContainsString('[TM]', $result->document->getSegmentPairs()[0]->target->getPlainText());
    }

    public function test_match_stats_counts_correctly(): void
    {
        // 3 segments: one exact, one fuzzy, one unmatched
        $exactDoc  = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', [
            new SegmentPair(new Segment('s0', ['exact segment'])),
            new SegmentPair(new Segment('s1', ['fuzzy segment'])),
            new SegmentPair(new Segment('s2', ['unmatched segment'])),
        ]);

        // TM that returns exact for 'exact', fuzzy for 'fuzzy', nothing for 'unmatched'
        $tm = new class implements TranslationMemoryInterface {
            public function lookup(Segment $source, string $sourceLanguage, string $targetLanguage, float $minScore = 0.7, int $maxResults = 5): array
            {
                $text = $source->getPlainText();
                if ($text === 'exact segment') {
                    return [new MatchResult(
                        translationUnit: new TranslationUnit(
                            source: $source,
                            target: new Segment('t0', ['exact translation']),
                            sourceLanguage: $sourceLanguage,
                            targetLanguage: $targetLanguage,
                            createdAt: new \DateTimeImmutable(),
                        ),
                        score: 1.0,
                        type: MatchType::EXACT,
                    )];
                }
                if ($text === 'fuzzy segment') {
                    return [new MatchResult(
                        translationUnit: new TranslationUnit(
                            source: $source,
                            target: new Segment('t1', ['fuzzy translation']),
                            sourceLanguage: $sourceLanguage,
                            targetLanguage: $targetLanguage,
                            createdAt: new \DateTimeImmutable(),
                        ),
                        score: 0.8,
                        type: MatchType::FUZZY,
                    )];
                }
                return [];
            }
            public function store(TranslationUnit $unit): void {}
            public function import(string $tmxFilePath): int { return 0; }
            public function export(string $tmxFilePath): int { return 0; }
        };

        $registry = $this->makeRegistry($this->makeFilter($exactDoc));
        $runner   = $this->makeRunner(registry: $registry, tm: $tm);
        $result   = $runner->process('/tmp/test.txt', 'fr-FR');

        $this->assertSame(1, $result->matchStats->exact);
        $this->assertSame(1, $result->matchStats->fuzzy);
        $this->assertSame(0, $result->matchStats->mt);
        $this->assertSame(1, $result->matchStats->unmatched);
    }

    public function test_qa_throws_workflow_exception_when_severity_breached(): void
    {
        $doc = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', [
            new SegmentPair(new Segment('s0', ['Hello'])), // no target → EmptyTranslationCheck ERROR
        ]);

        $qaRunner = new QualityRunner();
        $qaRunner->register(new EmptyTranslationCheck());

        $options = WorkflowOptions::defaults();
        $options->writeXliff = false;
        $options->qaFailOnSeverity = 'error';

        $runner = new WorkflowRunner(
            fileFilterRegistry: $this->makeRegistry($this->makeFilter($doc)),
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: 'en-US',
            qaRunner: $qaRunner,
            options: $options,
        );

        $this->expectException(WorkflowException::class);
        $runner->process('/tmp/test.txt', 'fr-FR');
    }

    public function test_qa_does_not_throw_when_severity_below_threshold(): void
    {
        $doc = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', [
            new SegmentPair(new Segment('s0', ['Hello'])), // no target → ERROR
        ]);

        $qaRunner = new QualityRunner();
        $qaRunner->register(new EmptyTranslationCheck());

        $options = WorkflowOptions::defaults();
        $options->writeXliff = false;
        $options->qaFailOnSeverity = null; // never throw

        $runner = new WorkflowRunner(
            fileFilterRegistry: $this->makeRegistry($this->makeFilter($doc)),
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: 'en-US',
            qaRunner: $qaRunner,
            options: $options,
        );

        $result = $runner->process('/tmp/test.txt', 'fr-FR');
        $this->assertNotEmpty($result->qaIssues);
    }

    public function test_progress_callback_fires_with_correct_index_and_total(): void
    {
        $doc = $this->makeDoc(['First', 'Second', 'Third']);
        $registry = $this->makeRegistry($this->makeFilter($doc));

        $options = WorkflowOptions::defaults();
        $options->writeXliff = false;

        $runner = new WorkflowRunner(
            fileFilterRegistry: $registry,
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: 'en-US',
            options: $options,
        );

        $calls = [];
        $runner->onSegmentProcessed(function (SegmentPair $pair, int $index, int $total) use (&$calls): void {
            $calls[] = ['index' => $index, 'total' => $total];
        });

        $runner->process('/tmp/test.txt', 'fr-FR');

        $this->assertCount(3, $calls);
        $this->assertSame(0, $calls[0]['index']);
        $this->assertSame(3, $calls[0]['total']);
        $this->assertSame(2, $calls[2]['index']);
        $this->assertSame(3, $calls[2]['total']);
    }

    public function test_timings_keys_present_in_result(): void
    {
        $doc    = $this->makeDoc(['Hello']);
        $runner = $this->makeRunner($this->makeRegistry($this->makeFilter($doc)));

        $result = $runner->process('/tmp/test.txt', 'fr-FR');

        foreach (['extract', 'segment', 'tm', 'terminology', 'mt', 'qa', 'xliff'] as $key) {
            $this->assertArrayHasKey($key, $result->timings, "Missing timing key: {$key}");
        }
    }
}
```

- [ ] **Step 2: Run tests — expect failure (WorkflowRunner not found)**

```bash
cd packages/workflow
vendor/bin/phpunit tests/WorkflowRunnerTest.php
```

Expected: error/failure — class not found.

- [ ] **Step 3: Create `src/WorkflowRunner.php`**

```php
<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

use CatFramework\Core\Contract\MachineTranslationInterface;
use CatFramework\Core\Contract\TerminologyProviderInterface;
use CatFramework\Core\Contract\TranslationMemoryInterface;
use CatFramework\Core\Enum\QualitySeverity;
use CatFramework\Core\Enum\SegmentState;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\QualityIssue;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Qa\QualityRunner;
use CatFramework\Segmentation\SrxSegmentationEngine;
use CatFramework\Workflow\Exception\WorkflowException;
use CatFramework\Xliff\XliffWriter;

final class WorkflowRunner implements WorkflowRunnerInterface
{
    private ?callable $onSegmentProcessed = null;

    public function __construct(
        private readonly FileFilterRegistry $fileFilterRegistry,
        private readonly SrxSegmentationEngine $segmentationEngine,
        private readonly XliffWriter $xliffWriter,
        private readonly string $sourceLang,
        private readonly ?TranslationMemoryInterface $translationMemory = null,
        private readonly ?TerminologyProviderInterface $terminologyProvider = null,
        private readonly ?MachineTranslationInterface $mtAdapter = null,
        private readonly ?QualityRunner $qaRunner = null,
        private readonly WorkflowOptions $options = new WorkflowOptions(),
    ) {}

    public function onSegmentProcessed(callable $cb): void
    {
        $this->onSegmentProcessed = $cb;
    }

    public function process(string $filePath, string $targetLang): WorkflowResult
    {
        $timings = [];

        // Step 1: Extract
        $t = microtime(true);
        $filter = $this->fileFilterRegistry->getFilter($filePath);
        $doc = $filter->extract($filePath, $this->sourceLang, $targetLang);
        $timings['extract'] = microtime(true) - $t;

        // Step 2: Segmentation — expand multi-sentence structural units
        // BilingualDocument::$segmentPairs is private, so we rebuild a new document
        // after the expansion pass rather than mutating in place.
        $t = microtime(true);
        $expandedPairs = [];
        foreach ($doc->getSegmentPairs() as $pair) {
            $sentences = $this->segmentationEngine->segment($pair->source, $this->sourceLang);
            if (count($sentences) <= 1) {
                $expandedPairs[] = $pair;
            } else {
                foreach ($sentences as $sentence) {
                    $expandedPairs[] = new SegmentPair(source: $sentence);
                }
            }
        }
        $doc = new BilingualDocument(
            sourceLanguage: $doc->sourceLanguage,
            targetLanguage: $doc->targetLanguage,
            originalFile: $doc->originalFile,
            mimeType: $doc->mimeType,
            segmentPairs: $expandedPairs,
            skeleton: $doc->skeleton,
        );
        $timings['segment'] = microtime(true) - $t;

        // Steps 3a–e: Per-segment processing
        $exact = $fuzzy = $mt = $unmatched = 0;
        $total = count($expandedPairs);
        $tmTime = $terminologyTime = $mtTime = 0.0;

        foreach ($expandedPairs as $index => $pair) {
            $tmBestScore = 0.0;
            $tmMatched   = false;

            // TM lookup
            if ($this->translationMemory !== null) {
                $t = microtime(true);
                $matches = $this->translationMemory->lookup($pair->source, $this->sourceLang, $targetLang);
                $tmTime += microtime(true) - $t;

                if ($matches !== []) {
                    $best        = $matches[0];
                    $tmBestScore = $best->score;
                    $tmMatched   = true;

                    $pair->target = $best->translationUnit->target;
                    $pair->state  = SegmentState::TRANSLATED;

                    if ($best->score >= $this->options->autoConfirmThreshold) {
                        $pair->isLocked = true;
                        $exact++;
                    } else {
                        $fuzzy++;
                    }
                }
            }

            // Terminology — run for timing; context is readonly so results cannot be stored on the pair
            if ($this->terminologyProvider !== null) {
                $t = microtime(true);
                $this->terminologyProvider->recognize(
                    $pair->source->getPlainText(),
                    $this->sourceLang,
                    $targetLang,
                );
                $terminologyTime += microtime(true) - $t;
            }

            // MT fill
            if (!$tmMatched && $tmBestScore < $this->options->mtFillThreshold && $this->mtAdapter !== null) {
                $t = microtime(true);
                $pair->target = $this->mtAdapter->translate($pair->source, $this->sourceLang, $targetLang);
                $pair->state  = SegmentState::TRANSLATED;
                $mtTime      += microtime(true) - $t;
                $mt++;
            } elseif (!$tmMatched) {
                $unmatched++;
            }

            // Progress callback
            if ($this->onSegmentProcessed !== null) {
                ($this->onSegmentProcessed)($pair, $index, $total);
            }
        }

        $timings['tm']          = $tmTime;
        $timings['terminology'] = $terminologyTime;
        $timings['mt']          = $mtTime;

        // Step 4: QA
        $t = microtime(true);
        $qaIssues = [];
        if ($this->qaRunner !== null) {
            $qaIssues = $this->qaRunner->run($doc);
            $this->enforceQaThreshold($qaIssues);
        }
        $timings['qa'] = microtime(true) - $t;

        // Step 5: XLIFF
        $t = microtime(true);
        $xliffPath = null;
        if ($this->options->writeXliff) {
            $dir       = $this->options->outputDir !== '' ? $this->options->outputDir : dirname($filePath);
            $xliffPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . basename($filePath) . '.xlf';
            $this->xliffWriter->write($doc, $xliffPath);
        }
        $timings['xliff'] = microtime(true) - $t;

        return new WorkflowResult(
            document: $doc,
            qaIssues: $qaIssues,
            matchStats: new TmMatchStats(
                exact: $exact,
                fuzzy: $fuzzy,
                mt: $mt,
                unmatched: $unmatched,
            ),
            xliffPath: $xliffPath,
            timings: $timings,
        );
    }

    /** @param QualityIssue[] $issues */
    private function enforceQaThreshold(array $issues): void
    {
        if ($this->options->qaFailOnSeverity === null || $issues === []) {
            return;
        }

        $threshold      = QualitySeverity::from($this->options->qaFailOnSeverity);
        $thresholdLevel = $this->severityLevel($threshold);

        foreach ($issues as $issue) {
            if ($this->severityLevel($issue->severity) >= $thresholdLevel) {
                throw new WorkflowException(
                    "QA check '{$issue->checkId}' failed with severity {$issue->severity->value}: {$issue->message}"
                );
            }
        }
    }

    private function severityLevel(QualitySeverity $severity): int
    {
        return match ($severity) {
            QualitySeverity::INFO    => 0,
            QualitySeverity::WARNING => 1,
            QualitySeverity::ERROR   => 2,
        };
    }
}
```

- [ ] **Step 4: Run tests — expect all passing**

```bash
cd packages/workflow
vendor/bin/phpunit tests/WorkflowRunnerTest.php
```

Expected: `9 / 9 tests OK` (or however many tests are in the file — count carefully from the test methods above).

- [ ] **Step 5: Commit**

```bash
git add packages/workflow/src/WorkflowRunner.php packages/workflow/tests/WorkflowRunnerTest.php
git commit -m "feat(workflow): implement WorkflowRunner with full pipeline"
```

---

## Task 5: ProjectWorkflowBuilder

**Files:**
- Create: `packages/workflow/src/ProjectWorkflowBuilder.php`
- Create: `packages/workflow/tests/ProjectWorkflowBuilderTest.php`

**Hydration logic in `build(string $targetLang, FileFilterRegistry $registry)`:**

1. **TM:** For each `TmConfig` in `$manifest->tm`, create `PDO('sqlite:' . $tmConfig->path)` then `new SqliteTranslationMemory($pdo)`. Use the first one (multi-TM round-robin is out of scope for A2).
2. **Glossary:** For each `GlossaryConfig` in `$manifest->glossaries`, create `new SqliteTerminologyProvider($config->path)`. Use the first one.
3. **MT:** If `$manifest->mt !== null`:
   - Check `GuzzleHttp\Client` class exists; throw `WorkflowException("HTTP client required for MT — install guzzlehttp/guzzle")` if not.
   - Map adapter name to class via `self::MT_ADAPTERS`. Throw `WorkflowException("Unknown MT adapter: {$name}")` for unknown names.
   - `'deepl'` → `new DeepLAdapter($httpClient, $httpFactory, $httpFactory, $manifest->mt->apiKey)`
   - `'google'` → throw `WorkflowException("Google adapter requires a project ID — use GoogleTranslateAdapter directly")`
4. **QA:** If `$manifest->qa->checks !== []`, create `new QualityRunner()` and register each check by name. Use `self::PAIR_CHECK_MAP` for per-pair checks and `self::DOC_CHECK_MAP` for document checks. Throw `WorkflowException("Unknown QA check: {$name}")` for unrecognised names.
5. **Options:** Copy `$manifest->mt->fillThreshold` to `$options->mtFillThreshold` and `$manifest->qa->failOnSeverity` to `$options->qaFailOnSeverity`.
6. Return `new WorkflowRunner(...)`.

**PSR-17 factory note:** GuzzleHttp\Psr7\HttpFactory implements both `RequestFactoryInterface` and `StreamFactoryInterface`. Instantiate one `$httpFactory = new \GuzzleHttp\Psr7\HttpFactory()` and pass it for both parameters.

**Check name maps:**
```
PAIR_CHECK_MAP (register via QualityRunner::register()):
  'EmptyTranslationCheck' => EmptyTranslationCheck::class
  'TagConsistencyCheck' => TagConsistencyCheck::class
  'NumberConsistencyCheck' => NumberConsistencyCheck::class
  'WhitespaceCheck' => WhitespaceCheck::class
  'DoubleSpaceCheck' => DoubleSpaceCheck::class
  'TerminologyConsistencyCheck' => TerminologyConsistencyCheck::class  ← needs $terminologyProvider arg

DOC_CHECK_MAP (register via QualityRunner::registerDocumentCheck()):
  'SegmentConsistencyCheck' => SegmentConsistencyCheck::class
```

- [ ] **Step 1: Write the failing tests**

Create `packages/workflow/tests/ProjectWorkflowBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Workflow\Tests;

use CatFramework\FilterPlaintext\PlainTextFilter;
use CatFramework\Project\Model\FilterConfig;
use CatFramework\Project\Model\GlossaryConfig;
use CatFramework\Project\Model\MtConfig;
use CatFramework\Project\Model\ProjectManifest;
use CatFramework\Project\Model\QaConfig;
use CatFramework\Project\Model\TmConfig;
use CatFramework\Workflow\Exception\WorkflowException;
use CatFramework\Workflow\FileFilterRegistry;
use CatFramework\Workflow\ProjectWorkflowBuilder;
use CatFramework\Workflow\WorkflowRunner;
use PHPUnit\Framework\TestCase;

final class ProjectWorkflowBuilderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/catfw-workflow-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) {
            is_file($f) && unlink($f);
        }
        rmdir($this->tmpDir);
    }

    private function makeMinimalManifest(): ProjectManifest
    {
        return new ProjectManifest(
            name: 'test',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR'],
            tm: [],
            glossaries: [],
            mt: null,
            qa: new QaConfig(checks: [], failOnSeverity: null),
            filters: new FilterConfig(),
            basePath: $this->tmpDir,
        );
    }

    private function makeRegistry(): FileFilterRegistry
    {
        $registry = new FileFilterRegistry();
        $registry->register(new PlainTextFilter());
        return $registry;
    }

    public function test_build_returns_workflow_runner_for_minimal_manifest(): void
    {
        $builder = new ProjectWorkflowBuilder($this->makeMinimalManifest());
        $runner  = $builder->build('fr-FR', $this->makeRegistry());

        $this->assertInstanceOf(WorkflowRunner::class, $runner);
    }

    public function test_build_with_sqlite_tm_returns_runner(): void
    {
        $dbPath = $this->tmpDir . '/test.db';
        // Create an empty SQLite database
        $pdo = new \PDO("sqlite:{$dbPath}");
        $pdo->exec("CREATE TABLE IF NOT EXISTS translation_units (id INTEGER PRIMARY KEY)");
        unset($pdo);

        $manifest = new ProjectManifest(
            name: 'test',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR'],
            tm: [new TmConfig(path: $dbPath, readOnly: false)],
            glossaries: [],
            mt: null,
            qa: new QaConfig(checks: [], failOnSeverity: null),
            filters: new FilterConfig(),
            basePath: $this->tmpDir,
        );

        $builder = new ProjectWorkflowBuilder($manifest);
        $runner  = $builder->build('fr-FR', $this->makeRegistry());

        $this->assertInstanceOf(WorkflowRunner::class, $runner);
    }

    public function test_unknown_mt_adapter_throws_workflow_exception(): void
    {
        $manifest = new ProjectManifest(
            name: 'test',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR'],
            tm: [],
            glossaries: [],
            mt: new MtConfig(adapter: 'nonexistent-adapter', apiKey: 'key', fillThreshold: 0.0),
            qa: new QaConfig(checks: [], failOnSeverity: null),
            filters: new FilterConfig(),
            basePath: $this->tmpDir,
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Unknown MT adapter');

        $builder = new ProjectWorkflowBuilder($manifest);
        $builder->build('fr-FR', $this->makeRegistry());
    }

    public function test_unknown_qa_check_throws_workflow_exception(): void
    {
        $manifest = new ProjectManifest(
            name: 'test',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR'],
            tm: [],
            glossaries: [],
            mt: null,
            qa: new QaConfig(checks: ['NonExistentCheck'], failOnSeverity: null),
            filters: new FilterConfig(),
            basePath: $this->tmpDir,
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Unknown QA check');

        $builder = new ProjectWorkflowBuilder($manifest);
        $builder->build('fr-FR', $this->makeRegistry());
    }

    public function test_build_with_empty_translation_check_returns_runner(): void
    {
        $manifest = new ProjectManifest(
            name: 'test',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR'],
            tm: [],
            glossaries: [],
            mt: null,
            qa: new QaConfig(checks: ['EmptyTranslationCheck'], failOnSeverity: 'error'),
            filters: new FilterConfig(),
            basePath: $this->tmpDir,
        );

        $builder = new ProjectWorkflowBuilder($manifest);
        $runner  = $builder->build('fr-FR', $this->makeRegistry());

        $this->assertInstanceOf(WorkflowRunner::class, $runner);
    }
}
```

- [ ] **Step 2: Run tests — expect failure (ProjectWorkflowBuilder not found)**

```bash
cd packages/workflow
vendor/bin/phpunit tests/ProjectWorkflowBuilderTest.php
```

Expected: error — class not found.

- [ ] **Step 3: Check what SqliteTranslationMemory's schema expects**

Before implementing, confirm the exact table structure `SqliteTranslationMemory` expects so the integration test's temp SQLite DB won't cause errors. Run:

```bash
grep -r "CREATE TABLE" packages/translation-memory/src/
```

If the test uses an empty DB (no schema), `build()` should still succeed since it only constructs the object, not queries it. The test only calls `build()`, not `process()`. If the constructor does schema validation, add the correct schema to the test's temp DB creation.

- [ ] **Step 4: Create `src/ProjectWorkflowBuilder.php`**

```php
<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

use CatFramework\Project\Model\ProjectManifest;
use CatFramework\Qa\Check\DoubleSpaceCheck;
use CatFramework\Qa\Check\EmptyTranslationCheck;
use CatFramework\Qa\Check\NumberConsistencyCheck;
use CatFramework\Qa\Check\SegmentConsistencyCheck;
use CatFramework\Qa\Check\TagConsistencyCheck;
use CatFramework\Qa\Check\TerminologyConsistencyCheck;
use CatFramework\Qa\Check\WhitespaceCheck;
use CatFramework\Qa\QualityRunner;
use CatFramework\Segmentation\SrxSegmentationEngine;
use CatFramework\Terminology\Provider\SqliteTerminologyProvider;
use CatFramework\TranslationMemory\SqliteTranslationMemory;
use CatFramework\Workflow\Exception\WorkflowException;
use CatFramework\Xliff\XliffWriter;
use PDO;

final class ProjectWorkflowBuilder
{
    private const array MT_ADAPTERS = ['deepl', 'google'];

    public function __construct(private readonly ProjectManifest $manifest) {}

    public function build(string $targetLang, FileFilterRegistry $registry): WorkflowRunner
    {
        $tm          = $this->buildTm();
        $terminology = $this->buildTerminology();
        $mtAdapter   = $this->buildMt();
        $qaRunner    = $this->buildQa($terminology);
        $options     = $this->buildOptions();

        return new WorkflowRunner(
            fileFilterRegistry: $registry,
            segmentationEngine: new SrxSegmentationEngine(),
            xliffWriter: new XliffWriter(),
            sourceLang: $this->manifest->sourceLang,
            translationMemory: $tm,
            terminologyProvider: $terminology,
            mtAdapter: $mtAdapter,
            qaRunner: $qaRunner,
            options: $options,
        );
    }

    private function buildTm(): ?SqliteTranslationMemory
    {
        if ($this->manifest->tm === []) {
            return null;
        }

        $config = $this->manifest->tm[0];
        $pdo    = new PDO('sqlite:' . $config->path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new SqliteTranslationMemory($pdo);
    }

    private function buildTerminology(): ?SqliteTerminologyProvider
    {
        if ($this->manifest->glossaries === []) {
            return null;
        }

        return new SqliteTerminologyProvider($this->manifest->glossaries[0]->path);
    }

    private function buildMt(): ?\CatFramework\Core\Contract\MachineTranslationInterface
    {
        if ($this->manifest->mt === null) {
            return null;
        }

        $adapterName = $this->manifest->mt->adapter;

        if (!in_array($adapterName, self::MT_ADAPTERS, true)) {
            throw new WorkflowException("Unknown MT adapter: {$adapterName}. Supported: " . implode(', ', self::MT_ADAPTERS));
        }

        if ($adapterName === 'google') {
            throw new WorkflowException(
                "Google adapter requires a project ID that is not in the manifest. " .
                "Instantiate GoogleTranslateAdapter directly and pass it to WorkflowRunner."
            );
        }

        if (!class_exists(\GuzzleHttp\Client::class)) {
            throw new WorkflowException(
                "HTTP client required for MT — install guzzlehttp/guzzle"
            );
        }

        $httpClient = new \GuzzleHttp\Client();
        $httpFactory = new \GuzzleHttp\Psr7\HttpFactory();

        return new \CatFramework\Mt\DeepL\DeepLAdapter(
            $httpClient,
            $httpFactory,
            $httpFactory,
            $this->manifest->mt->apiKey,
        );
    }

    private function buildQa(?SqliteTerminologyProvider $terminology): ?QualityRunner
    {
        if ($this->manifest->qa->checks === []) {
            return null;
        }

        $runner = new QualityRunner();

        $pairCheckMap = [
            'EmptyTranslationCheck'       => fn() => new EmptyTranslationCheck(),
            'TagConsistencyCheck'         => fn() => new TagConsistencyCheck(),
            'NumberConsistencyCheck'      => fn() => new NumberConsistencyCheck(),
            'WhitespaceCheck'             => fn() => new WhitespaceCheck(),
            'DoubleSpaceCheck'            => fn() => new DoubleSpaceCheck(),
            'TerminologyConsistencyCheck' => fn() => new TerminologyConsistencyCheck($terminology),
        ];

        $docCheckMap = [
            'SegmentConsistencyCheck' => fn() => new SegmentConsistencyCheck(),
        ];

        foreach ($this->manifest->qa->checks as $checkName) {
            if (isset($pairCheckMap[$checkName])) {
                $runner->register($pairCheckMap[$checkName]());
            } elseif (isset($docCheckMap[$checkName])) {
                $runner->registerDocumentCheck($docCheckMap[$checkName]());
            } else {
                throw new WorkflowException("Unknown QA check: {$checkName}");
            }
        }

        return $runner;
    }

    private function buildOptions(): WorkflowOptions
    {
        $options = WorkflowOptions::defaults();
        $options->qaFailOnSeverity = $this->manifest->qa->failOnSeverity;

        if ($this->manifest->mt !== null) {
            $options->mtFillThreshold = $this->manifest->mt->fillThreshold;
        }

        return $options;
    }
}
```

- [ ] **Step 5: Run tests — expect all passing**

```bash
cd packages/workflow
vendor/bin/phpunit tests/ProjectWorkflowBuilderTest.php
```

Expected: `5 / 5 tests OK`

If the SqliteTranslationMemory constructor does a schema migration (creates tables on first connect), the empty DB test may fail. If so, look at how `SqliteTranslationMemory::__construct()` is implemented and either: (a) add the required schema to the test's temp DB, or (b) use a valid empty DB path and let the constructor create the schema.

- [ ] **Step 6: Run the full test suite**

```bash
cd packages/workflow
vendor/bin/phpunit
```

Expected: all tests pass (FileFilterRegistryTest + WorkflowRunnerTest + ProjectWorkflowBuilderTest).

- [ ] **Step 7: Commit**

```bash
git add packages/workflow/src/ProjectWorkflowBuilder.php packages/workflow/tests/ProjectWorkflowBuilderTest.php
git commit -m "feat(workflow): implement ProjectWorkflowBuilder"
```

---

## Task 6: Final Verification and PR Prep

**Files:** None created. Verify, run tests, clean up.

- [ ] **Step 1: Run the full test suite one final time**

```bash
cd packages/workflow
vendor/bin/phpunit --colors=always
```

Expected: all tests passing, zero failures, zero warnings.

- [ ] **Step 2: Verify namespace autoloading is clean**

```bash
composer dump-autoload
```

Expected: no errors.

- [ ] **Step 3: Check for missing imports**

Look at each `src/` file and verify every class used is imported with a `use` statement. PHP will fail at runtime rather than parse-time if a class is used without import and is not in the same namespace.

- [ ] **Step 4: Final commit if any fixes were needed**

If any fixes were made in steps 1–3, commit them:

```bash
git add packages/workflow/
git commit -m "fix(workflow): address issues from final verification"
```

- [ ] **Step 5: Report completion**

Report to the orchestrator (the parent session) that all tasks are complete with:
- Total test count
- Any deviations from the plan (e.g., constructor signature differences discovered during implementation)
- Any open issues or TODOs discovered

---

## Self-Review Against Spec

**Spec requirements checklist:**

| Requirement | Covered in |
|---|---|
| `FileFilterRegistry` — register, getFilter, throws on no match | Task 3 |
| `WorkflowRunnerInterface::process()` | Task 3 |
| `WorkflowRunner` constructor (all dependencies, nullable) | Task 4 |
| `onSegmentProcessed(callable)` | Task 4 |
| Extract → segment → TM → terminology → MT pipeline | Task 4 |
| `autoConfirmThreshold` → lock | Task 4 |
| `mtFillThreshold` MT fill | Task 4 |
| `matchStats` (exact/fuzzy/mt/unmatched) | Task 4 |
| QA severity threshold → WorkflowException | Task 4 |
| XLIFF write with outputDir resolution | Task 4 |
| `$timings` all 7 keys | Task 4 |
| Progress callback with index + total | Task 4 |
| `WorkflowOptions` (all 5 fields) | Task 2 |
| `TmMatchStats`, `WorkflowResult` | Task 2 |
| `WorkflowException` | Task 2 |
| `ProjectWorkflowBuilder::build()` hydration | Task 5 |
| TM via PDO + SqliteTranslationMemory | Task 5 |
| Terminology via SqliteTerminologyProvider | Task 5 |
| MT adapter name map (deepl/google) | Task 5 |
| Unknown MT adapter throws WorkflowException | Task 5 |
| Unknown QA check throws WorkflowException | Task 5 |
| GuzzleHttp check before MT instantiation | Task 5 |
| QaFailOnSeverity copied from manifest to options | Task 5 |
| mtFillThreshold copied from manifest to options | Task 5 |

**Not in scope for A2 (documented):**
- Full end-to-end with real DOCX/XLIFF files
- HTTP calls to real MT APIs
- Multi-TM round-robin lookup (uses first TM only)
- PSR-14 event dispatcher (single callback instead)
- `QualityRunner::runOnDocument()` called in WorkflowRunner (only `run()` per spec step 4)
- Google MT adapter via ProjectWorkflowBuilder (needs projectId not in manifest)
- Terminology results stored in `SegmentPair::context` (readonly — A2 limitation)
