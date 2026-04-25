# Design: catframework/project (Phase 4, Track A1)

**Date:** 2026-04-25
**Status:** Approved

---

## Overview

`catframework/project` introduces a standard, portable project format for describing
a translation job. It provides:

1. `ProjectManifest` — a typed value object parsed from a `catproject.json` file
2. `CatpackArchive` — reads and writes a `.catpack` ZIP archive
3. `ProjectLoader` — loads and validates a manifest (phase 1 of two-phase validation)

It depends only on `catframework/core` and `ext-zip`. The dependency arrow runs
**toward** this package: `catframework/workflow` (A2) will depend on it, not vice versa.

---

## Package structure

```
packages/project/
├── composer.json
├── phpunit.xml
├── src/
│   ├── Exception/
│   │   ├── ManifestException.php
│   │   └── ProjectException.php
│   ├── Model/
│   │   ├── ProjectManifest.php
│   │   ├── TmConfig.php
│   │   ├── GlossaryConfig.php
│   │   ├── MtConfig.php
│   │   ├── QaConfig.php
│   │   └── FilterConfig.php
│   ├── CatpackArchive.php
│   └── ProjectLoader.php
└── tests/
```

---

## composer.json dependencies

```json
{
    "require": {
        "php": "^8.2",
        "ext-zip": "*",
        "catframework/core": "^0.1"
    }
}
```

No dependency on `catframework/workflow` — that relationship is inverted.

---

## Data models

### `ProjectManifest`

Readonly value object. Constructed by `ProjectLoader::getManifest()`.
All `${ENV_VAR}` interpolation is resolved before construction — the manifest
holds only concrete values.

```php
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

`basePath` is the directory containing `catproject.json`. All relative paths in
`tm`, `glossaries`, etc. are resolved against it.

### Config value objects

```php
final class TmConfig
{
    public function __construct(
        public readonly string $path,
        public readonly bool $readOnly,
    ) {}
}

final class GlossaryConfig
{
    public function __construct(
        public readonly string $path,
        public readonly bool $readOnly,
    ) {}
}

final class MtConfig
{
    public function __construct(
        public readonly string $adapter,
        public readonly string $apiKey,
        public readonly float $fillThreshold,
    ) {}
}

final class QaConfig
{
    public function __construct(
        /** @var string[] */
        public readonly array $checks,
        public readonly ?string $failOnSeverity,  // "warning" | "error" | null
    ) {}
}

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

---

## `ProjectLoader`

Two-phase validation. Phase 1 (this package). Phase 2 deferred to A2.

```php
final class ProjectLoader
{
    public function __construct(private readonly string $manifestPath) {}

    /**
     * Phase 1: read JSON, resolve ${ENV_VAR}, validate structure.
     * Throws ManifestException on: missing required field, wrong type,
     * malformed JSON, unreadable file.
     */
    public function getManifest(): ProjectManifest;

    /**
     * Resolves a manifest-relative path to an absolute path.
     * Does NOT check whether the path exists — that is Phase 2.
     */
    public static function resolvePath(string $basePath, string $relative): string;
}
```

`buildWorkflowRunner()` is intentionally absent from A1. It will be added in A2
inside `catframework/workflow`, which constructs `ProjectLoader` and calls
`getManifest()`, then validates paths/adapters and hydrates the runner.

---

## `CatpackArchive`

ZIP-backed portable project archive. Static factory, no public constructor.

```php
final class CatpackArchive
{
    public static function create(string $outputPath, ProjectManifest $manifest): self;
    public static function open(string $catpackPath): self;

    public function addSourceFile(string $filePath, ?string $archiveName = null): void;
    public function addTm(string $dbPath, string $archiveName): void;
    public function addGlossary(string $dbPath, string $archiveName): void;
    public function addXliff(string $xliffPath, string $archiveName): void;

    public function getManifest(): ProjectManifest;
    public function extractTo(string $directory): void;
    public function save(): void;
}
```

**Internal layout** (matches D22):

```
my-project.catpack
├── catproject.json
├── source/
├── tm/
├── glossaries/
└── xliff/
```

**Key rules:**
- `create()` holds `ZipArchive` open until `save()` — no partial writes on disk
- `open()` reads `catproject.json` immediately; throws `ManifestException` if absent or malformed
- `addTm()` and `addGlossary()` use `ZipArchive::CM_STORE` (no compression on SQLite files)
- `extractTo()` sets `basePath` on the returned manifest to the extraction directory

---

## Error handling

| Exception | Thrown by | Cause |
|---|---|---|
| `ManifestException` | `ProjectLoader::getManifest()`, `CatpackArchive::open()` | Bad JSON, missing required field, wrong type, invalid BCP 47 format |
| `ProjectException` | Deferred to A2 | Path not found, unknown adapter name |

---

## Testing strategy

- **Config value objects / `ProjectManifest`:** unit tests with fixture arrays, no filesystem
- **`ProjectLoader::getManifest()`:** temp files in tests; minimal valid manifest, one test per missing-required-field, one for `${ENV_VAR}` resolution
- **`CatpackArchive`:** integration tests using `sys_get_temp_dir()`; round-trip manifest, assert `CM_STORE` on `.db` entries, assert `extractTo()` layout
- Real files only — no mocking of `ZipArchive`, consistent with existing filter package conventions

**Not tested in A1:**
- Path existence validation (A2)
- `buildWorkflowRunner()` (does not exist yet)

---

## What is NOT in this package

| Item | Where it lives |
|---|---|
| `buildWorkflowRunner()` | `catframework/workflow` (A2) |
| Path/adapter validation | A2 `ProjectException` |
| `WorkflowRunner`, `WorkflowResult` | A2 |
