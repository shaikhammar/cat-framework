# catframework/project Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `catframework/project` package — `ProjectManifest`, `ProjectLoader`, and `CatpackArchive` — that provides a portable project format for translation jobs.

**Architecture:** `ProjectLoader::parseArray()` is the single source of truth for manifest parsing; both `ProjectLoader::getManifest()` (file I/O path) and `CatpackArchive::open()` (ZIP path) delegate to it. `CatpackArchive` is a thin wrapper around PHP's native `ZipArchive` with no external dependencies.

**Tech Stack:** PHP 8.2+, `ext-zip`, `catframework/core ^0.1`, PHPUnit 11

---

## Worktree

All work happens in:
```
C:/claude/cat-framework/.worktrees/phase-4-a1-project/
```

All `composer` and `./vendor/bin/phpunit` commands are run from:
```
C:/claude/cat-framework/.worktrees/phase-4-a1-project/packages/project/
```

---

## File map

| File | Responsibility |
|---|---|
| `packages/project/composer.json` | Package declaration, deps |
| `packages/project/phpunit.xml` | PHPUnit config |
| `packages/project/src/Exception/ManifestException.php` | Structural parse errors |
| `packages/project/src/Exception/ProjectException.php` | Runtime path/adapter errors (A2) |
| `packages/project/src/Model/TmConfig.php` | `{ path, readOnly }` value object |
| `packages/project/src/Model/GlossaryConfig.php` | `{ path, readOnly }` value object |
| `packages/project/src/Model/MtConfig.php` | `{ adapter, apiKey, fillThreshold }` value object |
| `packages/project/src/Model/QaConfig.php` | `{ checks[], failOnSeverity }` value object |
| `packages/project/src/Model/FilterConfig.php` | `{ docx[], xlsx[] }` value object |
| `packages/project/src/Model/ProjectManifest.php` | Typed, readonly manifest value object |
| `packages/project/src/ProjectLoader.php` | File I/O + manifest parsing + env var resolution |
| `packages/project/src/CatpackArchive.php` | ZIP-backed `.catpack` archive |
| `packages/project/tests/Model/ConfigValueObjectsTest.php` | Unit tests for all config value objects |
| `packages/project/tests/ProjectLoaderTest.php` | Unit + integration tests for loader |
| `packages/project/tests/CatpackArchiveTest.php` | Integration tests for archive (real temp files) |

---

## Task 1: Package scaffold

**Files:**
- Create: `packages/project/composer.json`
- Create: `packages/project/phpunit.xml`
- Create: `packages/project/src/` (directory)
- Create: `packages/project/tests/` (directory)

- [ ] **Step 1: Create composer.json**

```json
{
    "name": "catframework/project",
    "description": "Project manifest and catpack archive format for the CAT Framework",
    "type": "library",
    "license": "MIT",
    "version": "0.1.0",
    "require": {
        "php": "^8.2",
        "ext-zip": "*",
        "catframework/core": "^0.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "CatFramework\\Project\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "CatFramework\\Project\\Tests\\": "tests/"
        }
    },
    "repositories": [
        {
            "type": "path",
            "url": "../core"
        }
    ],
    "config": {
        "sort-packages": true
    }
}
```

- [ ] **Step 2: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="project">
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

- [ ] **Step 3: Install dependencies**

```bash
cd C:/claude/cat-framework/.worktrees/phase-4-a1-project/packages/project
composer install
```

Expected: `Generating autoload files` with no errors. If Avast blocks SSL, disable Avast shields temporarily and rerun.

- [ ] **Step 4: Commit**

```bash
git add packages/project/composer.json packages/project/phpunit.xml
git commit -m "feat(project): scaffold catframework/project package"
```

---

## Task 2: Exceptions and config value objects

**Files:**
- Create: `packages/project/src/Exception/ManifestException.php`
- Create: `packages/project/src/Exception/ProjectException.php`
- Create: `packages/project/src/Model/TmConfig.php`
- Create: `packages/project/src/Model/GlossaryConfig.php`
- Create: `packages/project/src/Model/MtConfig.php`
- Create: `packages/project/src/Model/QaConfig.php`
- Create: `packages/project/src/Model/FilterConfig.php`
- Create: `packages/project/src/Model/ProjectManifest.php`
- Create: `packages/project/tests/Model/ConfigValueObjectsTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/project/tests/Model/ConfigValueObjectsTest.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project\Tests\Model;

use CatFramework\Project\Model\FilterConfig;
use CatFramework\Project\Model\GlossaryConfig;
use CatFramework\Project\Model\MtConfig;
use CatFramework\Project\Model\ProjectManifest;
use CatFramework\Project\Model\QaConfig;
use CatFramework\Project\Model\TmConfig;
use PHPUnit\Framework\TestCase;

final class ConfigValueObjectsTest extends TestCase
{
    public function test_tm_config_stores_properties(): void
    {
        $tm = new TmConfig('tm/main.db', false);
        $this->assertSame('tm/main.db', $tm->path);
        $this->assertFalse($tm->readOnly);
    }

    public function test_glossary_config_stores_properties(): void
    {
        $g = new GlossaryConfig('glossaries/main.db', true);
        $this->assertSame('glossaries/main.db', $g->path);
        $this->assertTrue($g->readOnly);
    }

    public function test_mt_config_stores_properties(): void
    {
        $mt = new MtConfig('deepl', 'secret-key', 0.75);
        $this->assertSame('deepl', $mt->adapter);
        $this->assertSame('secret-key', $mt->apiKey);
        $this->assertSame(0.75, $mt->fillThreshold);
    }

    public function test_qa_config_stores_properties(): void
    {
        $qa = new QaConfig(['TagConsistencyCheck'], 'error');
        $this->assertSame(['TagConsistencyCheck'], $qa->checks);
        $this->assertSame('error', $qa->failOnSeverity);
    }

    public function test_qa_config_accepts_null_severity(): void
    {
        $qa = new QaConfig([], null);
        $this->assertNull($qa->failOnSeverity);
    }

    public function test_filter_config_defaults_to_empty(): void
    {
        $f = new FilterConfig();
        $this->assertSame([], $f->docx);
        $this->assertSame([], $f->xlsx);
    }

    public function test_project_manifest_stores_all_properties(): void
    {
        $manifest = new ProjectManifest(
            name: 'my-project',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR', 'de-DE'],
            tm: [new TmConfig('tm/main.db', false)],
            glossaries: [],
            mt: null,
            qa: new QaConfig([], null),
            filters: new FilterConfig(),
            basePath: '/tmp/project',
        );

        $this->assertSame('my-project', $manifest->name);
        $this->assertSame('en-US', $manifest->sourceLang);
        $this->assertSame(['fr-FR', 'de-DE'], $manifest->targetLangs);
        $this->assertCount(1, $manifest->tm);
        $this->assertNull($manifest->mt);
        $this->assertSame('/tmp/project', $manifest->basePath);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd C:/claude/cat-framework/.worktrees/phase-4-a1-project/packages/project
./vendor/bin/phpunit tests/Model/ConfigValueObjectsTest.php
```

Expected: FAIL — class not found errors.

- [ ] **Step 3: Create exceptions**

Create `packages/project/src/Exception/ManifestException.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project\Exception;

final class ManifestException extends \RuntimeException {}
```

Create `packages/project/src/Exception/ProjectException.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project\Exception;

final class ProjectException extends \RuntimeException {}
```

- [ ] **Step 4: Create config value objects**

Create `packages/project/src/Model/TmConfig.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project\Model;

final class TmConfig
{
    public function __construct(
        public readonly string $path,
        public readonly bool $readOnly,
    ) {}
}
```

Create `packages/project/src/Model/GlossaryConfig.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project\Model;

final class GlossaryConfig
{
    public function __construct(
        public readonly string $path,
        public readonly bool $readOnly,
    ) {}
}
```

Create `packages/project/src/Model/MtConfig.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project\Model;

final class MtConfig
{
    public function __construct(
        public readonly string $adapter,
        public readonly string $apiKey,
        public readonly float $fillThreshold,
    ) {}
}
```

Create `packages/project/src/Model/QaConfig.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project\Model;

final class QaConfig
{
    public function __construct(
        /** @var string[] */
        public readonly array $checks,
        public readonly ?string $failOnSeverity,
    ) {}
}
```

Create `packages/project/src/Model/FilterConfig.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project\Model;

final class FilterConfig
{
    public function __construct(
        /** @var array<string, mixed> */
        public readonly array $docx = [],
        /** @var array<string, mixed> */
        public readonly array $xlsx = [],
    ) {}
}
```

Create `packages/project/src/Model/ProjectManifest.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project\Model;

final class ProjectManifest
{
    public function __construct(
        public readonly string $name,
        public readonly string $sourceLang,
        /** @var string[] */
        public readonly array $targetLangs,
        /** @var TmConfig[] */
        public readonly array $tm,
        /** @var GlossaryConfig[] */
        public readonly array $glossaries,
        public readonly ?MtConfig $mt,
        public readonly QaConfig $qa,
        public readonly FilterConfig $filters,
        public readonly string $basePath,
    ) {}
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Model/ConfigValueObjectsTest.php
```

Expected: 7 tests, 0 failures.

- [ ] **Step 6: Commit**

```bash
git add packages/project/src/ packages/project/tests/
git commit -m "feat(project): add exceptions, config value objects, and ProjectManifest"
```

---

## Task 3: ProjectLoader — core parsing

**Files:**
- Create: `packages/project/src/ProjectLoader.php`
- Create: `packages/project/tests/ProjectLoaderTest.php`

- [ ] **Step 1: Write the failing tests**

Create `packages/project/tests/ProjectLoaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project\Tests;

use CatFramework\Project\Exception\ManifestException;
use CatFramework\Project\Model\ProjectManifest;
use CatFramework\Project\ProjectLoader;
use PHPUnit\Framework\TestCase;

final class ProjectLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cat-project-test-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*'));
        rmdir($this->tmpDir);
    }

    private function writeManifest(array $data): string
    {
        $path = $this->tmpDir . '/catproject.json';
        file_put_contents($path, json_encode($data));
        return $path;
    }

    private function minimalManifest(): array
    {
        return [
            'name' => 'test-project',
            'sourceLang' => 'en-US',
            'targetLangs' => ['fr-FR'],
            'tm' => [],
            'glossaries' => [],
            'qa' => ['checks' => [], 'failOnSeverity' => null],
        ];
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_loads_minimal_manifest(): void
    {
        $path = $this->writeManifest($this->minimalManifest());
        $manifest = (new ProjectLoader($path))->getManifest();

        $this->assertInstanceOf(ProjectManifest::class, $manifest);
        $this->assertSame('test-project', $manifest->name);
        $this->assertSame('en-US', $manifest->sourceLang);
        $this->assertSame(['fr-FR'], $manifest->targetLangs);
        $this->assertSame([], $manifest->tm);
        $this->assertSame([], $manifest->glossaries);
        $this->assertNull($manifest->mt);
        $this->assertSame($this->tmpDir, $manifest->basePath);
    }

    public function test_loads_tm_entries(): void
    {
        $data = $this->minimalManifest();
        $data['tm'] = [
            ['path' => 'tm/main.db', 'readOnly' => false],
            ['path' => 'tm/ref.db', 'readOnly' => true],
        ];
        $manifest = (new ProjectLoader($this->writeManifest($data)))->getManifest();

        $this->assertCount(2, $manifest->tm);
        $this->assertSame('tm/main.db', $manifest->tm[0]->path);
        $this->assertFalse($manifest->tm[0]->readOnly);
        $this->assertTrue($manifest->tm[1]->readOnly);
    }

    public function test_loads_mt_config(): void
    {
        $data = $this->minimalManifest();
        $data['mt'] = ['adapter' => 'deepl', 'apiKey' => 'abc123', 'fillThreshold' => 0.75];
        $manifest = (new ProjectLoader($this->writeManifest($data)))->getManifest();

        $this->assertNotNull($manifest->mt);
        $this->assertSame('deepl', $manifest->mt->adapter);
        $this->assertSame('abc123', $manifest->mt->apiKey);
        $this->assertSame(0.75, $manifest->mt->fillThreshold);
    }

    public function test_loads_qa_config(): void
    {
        $data = $this->minimalManifest();
        $data['qa'] = ['checks' => ['TagConsistencyCheck', 'EmptyTranslationCheck'], 'failOnSeverity' => 'error'];
        $manifest = (new ProjectLoader($this->writeManifest($data)))->getManifest();

        $this->assertSame(['TagConsistencyCheck', 'EmptyTranslationCheck'], $manifest->qa->checks);
        $this->assertSame('error', $manifest->qa->failOnSeverity);
    }

    public function test_filters_default_to_empty_when_absent(): void
    {
        $manifest = (new ProjectLoader($this->writeManifest($this->minimalManifest())))->getManifest();

        $this->assertSame([], $manifest->filters->docx);
        $this->assertSame([], $manifest->filters->xlsx);
    }

    public function test_loads_filter_overrides(): void
    {
        $data = $this->minimalManifest();
        $data['filters'] = ['docx' => ['mergeSplitRuns' => true], 'xlsx' => ['skipEmptyCells' => false]];
        $manifest = (new ProjectLoader($this->writeManifest($data)))->getManifest();

        $this->assertSame(['mergeSplitRuns' => true], $manifest->filters->docx);
        $this->assertSame(['skipEmptyCells' => false], $manifest->filters->xlsx);
    }

    // -------------------------------------------------------------------------
    // Validation — missing required fields
    // -------------------------------------------------------------------------

    public function test_throws_manifest_exception_for_missing_name(): void
    {
        $data = $this->minimalManifest();
        unset($data['name']);
        $this->expectException(ManifestException::class);
        (new ProjectLoader($this->writeManifest($data)))->getManifest();
    }

    public function test_throws_manifest_exception_for_missing_source_lang(): void
    {
        $data = $this->minimalManifest();
        unset($data['sourceLang']);
        $this->expectException(ManifestException::class);
        (new ProjectLoader($this->writeManifest($data)))->getManifest();
    }

    public function test_throws_manifest_exception_for_missing_target_langs(): void
    {
        $data = $this->minimalManifest();
        unset($data['targetLangs']);
        $this->expectException(ManifestException::class);
        (new ProjectLoader($this->writeManifest($data)))->getManifest();
    }

    public function test_throws_manifest_exception_for_empty_target_langs(): void
    {
        $data = $this->minimalManifest();
        $data['targetLangs'] = [];
        $this->expectException(ManifestException::class);
        (new ProjectLoader($this->writeManifest($data)))->getManifest();
    }

    public function test_throws_manifest_exception_for_invalid_json(): void
    {
        $path = $this->tmpDir . '/catproject.json';
        file_put_contents($path, 'not json {{{');
        $this->expectException(ManifestException::class);
        (new ProjectLoader($path))->getManifest();
    }

    public function test_throws_manifest_exception_for_missing_file(): void
    {
        $this->expectException(ManifestException::class);
        (new ProjectLoader($this->tmpDir . '/nonexistent.json'))->getManifest();
    }

    // -------------------------------------------------------------------------
    // Env var resolution
    // -------------------------------------------------------------------------

    public function test_resolves_env_var_in_api_key(): void
    {
        putenv('TEST_DEEPL_KEY=secret-from-env');
        $data = $this->minimalManifest();
        $data['mt'] = ['adapter' => 'deepl', 'apiKey' => '${TEST_DEEPL_KEY}', 'fillThreshold' => 0.0];
        $manifest = (new ProjectLoader($this->writeManifest($data)))->getManifest();
        putenv('TEST_DEEPL_KEY');

        $this->assertSame('secret-from-env', $manifest->mt->apiKey);
    }

    public function test_leaves_unresolved_env_var_literal_when_not_set(): void
    {
        putenv('UNSET_VAR_XYZ');
        $data = $this->minimalManifest();
        $data['mt'] = ['adapter' => 'deepl', 'apiKey' => '${UNSET_VAR_XYZ}', 'fillThreshold' => 0.0];
        $manifest = (new ProjectLoader($this->writeManifest($data)))->getManifest();

        $this->assertSame('${UNSET_VAR_XYZ}', $manifest->mt->apiKey);
    }

    // -------------------------------------------------------------------------
    // resolvePath
    // -------------------------------------------------------------------------

    public function test_resolve_path_joins_relative_to_base(): void
    {
        $result = ProjectLoader::resolvePath('/projects/myproject', 'tm/main.db');
        $this->assertSame('/projects/myproject' . DIRECTORY_SEPARATOR . 'tm/main.db', $result);
    }

    public function test_resolve_path_returns_absolute_path_unchanged(): void
    {
        $result = ProjectLoader::resolvePath('/projects/myproject', '/absolute/path/tm.db');
        $this->assertSame('/absolute/path/tm.db', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/ProjectLoaderTest.php
```

Expected: FAIL — `ProjectLoader` class not found.

- [ ] **Step 3: Implement ProjectLoader**

Create `packages/project/src/ProjectLoader.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project;

use CatFramework\Project\Exception\ManifestException;
use CatFramework\Project\Model\FilterConfig;
use CatFramework\Project\Model\GlossaryConfig;
use CatFramework\Project\Model\MtConfig;
use CatFramework\Project\Model\ProjectManifest;
use CatFramework\Project\Model\QaConfig;
use CatFramework\Project\Model\TmConfig;

final class ProjectLoader
{
    public function __construct(private readonly string $manifestPath) {}

    public function getManifest(): ProjectManifest
    {
        if (!is_file($this->manifestPath)) {
            throw new ManifestException("Manifest file not found: {$this->manifestPath}");
        }

        $json = file_get_contents($this->manifestPath);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new ManifestException("Invalid JSON in manifest: {$this->manifestPath}");
        }

        $data = self::resolveEnvVars($data);

        return self::parseArray($data, dirname(realpath($this->manifestPath)));
    }

    public static function parseArray(array $data, string $basePath): ProjectManifest
    {
        foreach (['name', 'sourceLang', 'targetLangs'] as $field) {
            if (!isset($data[$field])) {
                throw new ManifestException("Missing required field '{$field}' in manifest");
            }
        }

        if (!is_string($data['name']) || $data['name'] === '') {
            throw new ManifestException("Field 'name' must be a non-empty string");
        }

        if (!is_string($data['sourceLang'])) {
            throw new ManifestException("Field 'sourceLang' must be a string");
        }

        if (!is_array($data['targetLangs']) || $data['targetLangs'] === []) {
            throw new ManifestException("Field 'targetLangs' must be a non-empty array");
        }

        $tm = [];
        foreach ($data['tm'] ?? [] as $entry) {
            $tm[] = new TmConfig((string) $entry['path'], (bool) ($entry['readOnly'] ?? false));
        }

        $glossaries = [];
        foreach ($data['glossaries'] ?? [] as $entry) {
            $glossaries[] = new GlossaryConfig((string) $entry['path'], (bool) ($entry['readOnly'] ?? false));
        }

        $mt = null;
        if (isset($data['mt'])) {
            $mt = new MtConfig(
                (string) $data['mt']['adapter'],
                (string) ($data['mt']['apiKey'] ?? ''),
                (float) ($data['mt']['fillThreshold'] ?? 0.0),
            );
        }

        $qaData = $data['qa'] ?? [];
        $qa = new QaConfig(
            (array) ($qaData['checks'] ?? []),
            isset($qaData['failOnSeverity']) ? (string) $qaData['failOnSeverity'] : null,
        );

        $filterData = $data['filters'] ?? [];
        $filters = new FilterConfig(
            (array) ($filterData['docx'] ?? []),
            (array) ($filterData['xlsx'] ?? []),
        );

        return new ProjectManifest(
            name: $data['name'],
            sourceLang: $data['sourceLang'],
            targetLangs: $data['targetLangs'],
            tm: $tm,
            glossaries: $glossaries,
            mt: $mt,
            qa: $qa,
            filters: $filters,
            basePath: $basePath,
        );
    }

    public static function resolvePath(string $basePath, string $relative): string
    {
        if (str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative)) {
            return $relative;
        }

        return rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $relative;
    }

    private static function resolveEnvVars(array $data): array
    {
        array_walk_recursive($data, static function (mixed &$value): void {
            if (is_string($value)) {
                $value = preg_replace_callback(
                    '/\$\{([A-Z_][A-Z0-9_]*)\}/',
                    static fn(array $m): string => getenv($m[1]) !== false ? (string) getenv($m[1]) : $m[0],
                    $value,
                );
            }
        });

        return $data;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/ProjectLoaderTest.php
```

Expected: 17 tests, 0 failures.

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/phpunit
```

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/project/src/ProjectLoader.php packages/project/tests/ProjectLoaderTest.php
git commit -m "feat(project): implement ProjectLoader with env var resolution and two-phase validation"
```

---

## Task 4: CatpackArchive — create, save, open

**Files:**
- Create: `packages/project/src/CatpackArchive.php`
- Create: `packages/project/tests/CatpackArchiveTest.php`

- [ ] **Step 1: Write the failing tests**

Create `packages/project/tests/CatpackArchiveTest.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project\Tests;

use CatFramework\Project\CatpackArchive;
use CatFramework\Project\Exception\ManifestException;
use CatFramework\Project\Model\FilterConfig;
use CatFramework\Project\Model\GlossaryConfig;
use CatFramework\Project\Model\MtConfig;
use CatFramework\Project\Model\ProjectManifest;
use CatFramework\Project\Model\QaConfig;
use CatFramework\Project\Model\TmConfig;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class CatpackArchiveTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cat-archive-test-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeManifest(?MtConfig $mt = null): ProjectManifest
    {
        return new ProjectManifest(
            name: 'test-project',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR'],
            tm: [new TmConfig('tm/main.db', false)],
            glossaries: [],
            mt: $mt,
            qa: new QaConfig(['TagConsistencyCheck'], 'error'),
            filters: new FilterConfig(['mergeSplitRuns' => true]),
            basePath: $this->tmpDir,
        );
    }

    // -------------------------------------------------------------------------
    // create + save + open round-trip
    // -------------------------------------------------------------------------

    public function test_create_and_save_produces_zip_with_manifest(): void
    {
        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->save();

        $this->assertFileExists($outPath);

        $zip = new ZipArchive();
        $zip->open($outPath);
        $this->assertNotFalse($zip->getFromName('catproject.json'));
        $zip->close();
    }

    public function test_open_reads_manifest_from_zip(): void
    {
        $outPath = $this->tmpDir . '/test.catpack';
        $manifest = $this->makeManifest();
        CatpackArchive::create($outPath, $manifest)->save();

        $opened = CatpackArchive::open($outPath);
        $loaded = $opened->getManifest();

        $this->assertSame('test-project', $loaded->name);
        $this->assertSame('en-US', $loaded->sourceLang);
        $this->assertSame(['fr-FR'], $loaded->targetLangs);
        $this->assertCount(1, $loaded->tm);
        $this->assertSame('tm/main.db', $loaded->tm[0]->path);
        $this->assertFalse($loaded->tm[0]->readOnly);
        $this->assertSame(['TagConsistencyCheck'], $loaded->qa->checks);
        $this->assertSame('error', $loaded->qa->failOnSeverity);
        $this->assertSame(['mergeSplitRuns' => true], $loaded->filters->docx);
    }

    public function test_open_preserves_mt_config(): void
    {
        $outPath = $this->tmpDir . '/test.catpack';
        $mt = new MtConfig('deepl', 'api-key-123', 0.75);
        CatpackArchive::create($outPath, $this->makeManifest($mt))->save();

        $loaded = CatpackArchive::open($outPath)->getManifest();

        $this->assertNotNull($loaded->mt);
        $this->assertSame('deepl', $loaded->mt->adapter);
        $this->assertSame('api-key-123', $loaded->mt->apiKey);
        $this->assertSame(0.75, $loaded->mt->fillThreshold);
    }

    public function test_open_throws_manifest_exception_for_missing_catproject_json(): void
    {
        $outPath = $this->tmpDir . '/empty.catpack';
        $zip = new ZipArchive();
        $zip->open($outPath, ZipArchive::CREATE);
        $zip->addFromString('README.txt', 'not a catpack');
        $zip->close();

        $this->expectException(ManifestException::class);
        CatpackArchive::open($outPath);
    }

    public function test_open_throws_manifest_exception_for_non_existent_file(): void
    {
        $this->expectException(ManifestException::class);
        CatpackArchive::open($this->tmpDir . '/nonexistent.catpack');
    }

    // -------------------------------------------------------------------------
    // addSourceFile, addTm, addGlossary, addXliff
    // -------------------------------------------------------------------------

    public function test_add_source_file_places_file_in_source_directory(): void
    {
        $srcFile = $this->tmpDir . '/document.docx';
        file_put_contents($srcFile, 'fake docx content');

        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->addSourceFile($srcFile);
        $archive->save();

        $zip = new ZipArchive();
        $zip->open($outPath);
        $this->assertNotFalse($zip->getFromName('source/document.docx'));
        $zip->close();
    }

    public function test_add_source_file_accepts_custom_archive_name(): void
    {
        $srcFile = $this->tmpDir . '/document.docx';
        file_put_contents($srcFile, 'fake docx content');

        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->addSourceFile($srcFile, 'renamed.docx');
        $archive->save();

        $zip = new ZipArchive();
        $zip->open($outPath);
        $this->assertNotFalse($zip->getFromName('source/renamed.docx'));
        $zip->close();
    }

    public function test_add_tm_places_file_in_tm_directory_with_store_compression(): void
    {
        $dbFile = $this->tmpDir . '/main.db';
        file_put_contents($dbFile, str_repeat('x', 1024));

        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->addTm($dbFile, 'main.db');
        $archive->save();

        $zip = new ZipArchive();
        $zip->open($outPath);
        $stat = $zip->statName('tm/main.db');
        $zip->close();

        $this->assertNotFalse($stat);
        $this->assertSame(0, $stat['comp_method'], 'TM files must use CM_STORE (compression method 0)');
    }

    public function test_add_glossary_places_file_in_glossaries_directory_with_store_compression(): void
    {
        $dbFile = $this->tmpDir . '/terms.db';
        file_put_contents($dbFile, str_repeat('y', 1024));

        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->addGlossary($dbFile, 'terms.db');
        $archive->save();

        $zip = new ZipArchive();
        $zip->open($outPath);
        $stat = $zip->statName('glossaries/terms.db');
        $zip->close();

        $this->assertNotFalse($stat);
        $this->assertSame(0, $stat['comp_method'], 'Glossary files must use CM_STORE (compression method 0)');
    }

    public function test_add_xliff_places_file_in_xliff_directory(): void
    {
        $xliffFile = $this->tmpDir . '/document.xlf';
        file_put_contents($xliffFile, '<xliff/>');

        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->addXliff($xliffFile, 'document.xlf');
        $archive->save();

        $zip = new ZipArchive();
        $zip->open($outPath);
        $this->assertNotFalse($zip->getFromName('xliff/document.xlf'));
        $zip->close();
    }

    // -------------------------------------------------------------------------
    // extractTo
    // -------------------------------------------------------------------------

    public function test_extract_to_produces_correct_directory_layout(): void
    {
        $srcFile = $this->tmpDir . '/doc.docx';
        $dbFile  = $this->tmpDir . '/main.db';
        file_put_contents($srcFile, 'docx');
        file_put_contents($dbFile, 'sqlite');

        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->addSourceFile($srcFile);
        $archive->addTm($dbFile, 'main.db');
        $archive->save();

        $extractDir = $this->tmpDir . '/extracted';
        mkdir($extractDir);
        CatpackArchive::open($outPath)->extractTo($extractDir);

        $this->assertFileExists($extractDir . '/catproject.json');
        $this->assertFileExists($extractDir . '/source/doc.docx');
        $this->assertFileExists($extractDir . '/tm/main.db');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/CatpackArchiveTest.php
```

Expected: FAIL — `CatpackArchive` class not found.

- [ ] **Step 3: Implement CatpackArchive**

Create `packages/project/src/CatpackArchive.php`:

```php
<?php

declare(strict_types=1);

namespace CatFramework\Project;

use CatFramework\Project\Exception\ManifestException;
use CatFramework\Project\Model\ProjectManifest;
use ZipArchive;

final class CatpackArchive
{
    private function __construct(
        private readonly ZipArchive $zip,
        private readonly ProjectManifest $manifest,
    ) {}

    public static function create(string $outputPath, ProjectManifest $manifest): self
    {
        $zip = new ZipArchive();
        $zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('catproject.json', self::manifestToJson($manifest));

        return new self($zip, $manifest);
    }

    public static function open(string $catpackPath): self
    {
        $zip = new ZipArchive();

        if ($zip->open($catpackPath) !== true) {
            throw new ManifestException("Cannot open catpack: {$catpackPath}");
        }

        $json = $zip->getFromName('catproject.json');

        if ($json === false) {
            throw new ManifestException("catproject.json not found in archive: {$catpackPath}");
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new ManifestException("Invalid catproject.json in archive: {$catpackPath}");
        }

        $manifest = ProjectLoader::parseArray($data, dirname($catpackPath));

        return new self($zip, $manifest);
    }

    public function addSourceFile(string $filePath, ?string $archiveName = null): void
    {
        $this->zip->addFile($filePath, 'source/' . ($archiveName ?? basename($filePath)));
    }

    public function addTm(string $dbPath, string $archiveName): void
    {
        $entryName = 'tm/' . $archiveName;
        $this->zip->addFile($dbPath, $entryName);
        $this->zip->setCompressionName($entryName, ZipArchive::CM_STORE);
    }

    public function addGlossary(string $dbPath, string $archiveName): void
    {
        $entryName = 'glossaries/' . $archiveName;
        $this->zip->addFile($dbPath, $entryName);
        $this->zip->setCompressionName($entryName, ZipArchive::CM_STORE);
    }

    public function addXliff(string $xliffPath, string $archiveName): void
    {
        $this->zip->addFile($xliffPath, 'xliff/' . $archiveName);
    }

    public function getManifest(): ProjectManifest
    {
        return $this->manifest;
    }

    public function extractTo(string $directory): void
    {
        $this->zip->extractTo($directory);
    }

    public function save(): void
    {
        $this->zip->close();
    }

    private static function manifestToJson(ProjectManifest $manifest): string
    {
        $data = [
            'name'        => $manifest->name,
            'sourceLang'  => $manifest->sourceLang,
            'targetLangs' => $manifest->targetLangs,
            'tm'          => array_map(
                fn(object $tm) => ['path' => $tm->path, 'readOnly' => $tm->readOnly],
                $manifest->tm,
            ),
            'glossaries'  => array_map(
                fn(object $g) => ['path' => $g->path, 'readOnly' => $g->readOnly],
                $manifest->glossaries,
            ),
        ];

        if ($manifest->mt !== null) {
            $data['mt'] = [
                'adapter'       => $manifest->mt->adapter,
                'apiKey'        => $manifest->mt->apiKey,
                'fillThreshold' => $manifest->mt->fillThreshold,
            ];
        }

        $data['qa'] = [
            'checks'          => $manifest->qa->checks,
            'failOnSeverity'  => $manifest->qa->failOnSeverity,
        ];

        $data['filters'] = [
            'docx' => $manifest->filters->docx,
            'xlsx' => $manifest->filters->xlsx,
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/CatpackArchiveTest.php
```

Expected: 11 tests, 0 failures.

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/phpunit
```

Expected: All tests pass (28+ tests, 0 failures).

- [ ] **Step 6: Commit**

```bash
git add packages/project/src/CatpackArchive.php packages/project/tests/CatpackArchiveTest.php
git commit -m "feat(project): implement CatpackArchive with ZIP-backed .catpack format"
```

---

## Task 5: Final cleanup and PR

- [ ] **Step 1: Run full test suite one final time**

```bash
./vendor/bin/phpunit --testdox
```

Expected: All tests pass with descriptive output.

- [ ] **Step 2: Verify no untracked or unstaged files**

```bash
git status
```

Expected: clean working tree.

- [ ] **Step 3: Push branch and open PR**

```bash
git push -u origin phase-4-a1-project
```

Then open a PR titled: `feat: Add catframework/project package (Phase 4 A1)`

PR description:
- Introduces `ProjectManifest` — typed, readonly value object parsed from `catproject.json`
- Introduces `ProjectLoader` — two-phase validation; `getManifest()` validates structure, `buildWorkflowRunner()` deferred to A2
- Introduces `CatpackArchive` — ZIP-backed portable project archive with CM_STORE for SQLite files
- Resolves `${ENV_VAR}` syntax in manifest string values at load time
- No dependency on `catframework/workflow` — dependency arrow runs in the correct direction
