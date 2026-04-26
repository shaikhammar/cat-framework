# CAT Framework

An open-source, modular PHP framework for building computer-assisted translation (CAT) tools. Framework-agnostic, no Laravel dependency, designed for PHP 8.2+ with full UTF-8 and RTL support from day one.

## Packages

### Phase 1 — Core pipeline

| Package | Description |
|---|---|
| [`catframework/core`](packages/core) | Shared data models, contracts, and enums |
| [`catframework/srx`](packages/srx) | SRX 2.0 segmentation rule parser |
| [`catframework/segmentation`](packages/segmentation) | SRX-based sentence segmentation engine |
| [`catframework/filter-plaintext`](packages/filter-plaintext) | Plain text file filter (`.txt`) |
| [`catframework/filter-html`](packages/filter-html) | HTML file filter (`.html`, `.htm`) |
| [`catframework/xliff`](packages/xliff) | XLIFF 1.2 writer and reader |

### Phase 2 — TM, DOCX, QA, Terminology

| Package | Description |
|---|---|
| [`catframework/tmx`](packages/tmx) | TMX 1.4b parser and writer |
| [`catframework/translation-memory`](packages/translation-memory) | SQLite translation memory with exact and fuzzy matching |
| [`catframework/filter-docx`](packages/filter-docx) | DOCX file filter (`.docx`) with run merging and inline code preservation |
| [`catframework/qa`](packages/qa) | Per-segment quality checks (tags, numbers, whitespace, empty translations) |
| [`catframework/terminology`](packages/terminology) | TBX v2 import and term recognition with SQLite backend |

### Phase 3 — MT, Office filters, Cross-segment QA

| Package | Description |
|---|---|
| [`catframework/mt`](packages/mt) | Machine translation adapters for DeepL and Google Translate (PSR-18 HTTP client injected) |
| [`catframework/filter-xlsx`](packages/filter-xlsx) | Excel file filter (`.xlsx`) with shared-strings deduplication and cell-type awareness |
| [`catframework/filter-pptx`](packages/filter-pptx) | PowerPoint file filter (`.pptx`) — slide body and speaker notes only, DrawingML-safe |
| `catframework/qa` _(extended)_ | `SegmentConsistencyCheck` — cross-segment QA: flags identical source segments with divergent translations |

### Phase 4 — Project, Workflow, API Service

| Package / Project | Description |
|---|---|
| [`catframework/project`](packages/project) | Project manifest (`catproject.json`) and portable `.catpack` archive bundling source, TM, and glossary |
| [`catframework/workflow`](packages/workflow) | End-to-end translation workflow orchestrator: extract → segment → TM → MT → QA in one call |
| [`cat-framework-api`](https://github.com/catframework/cat-framework-api) _(separate repo)_ | Laravel REST API exposing all framework capabilities over HTTP — usable by any tech stack |

## Requirements

- PHP 8.2+
- `ext-mbstring` (segmentation, filter-plaintext, terminology)
- `ext-dom` + `ext-libxml` (srx, filter-html, xliff, tmx, terminology)
- `ext-xmlreader` (tmx — streaming mode for large files)
- `ext-pdo` + `ext-pdo_sqlite` (translation-memory, terminology)
- `ext-zip` (filter-docx)
- `ext-intl` (translation-memory, qa, terminology)

## How it fits together

```
Source file  (.txt / .html / .docx)
    │
    ▼
FileFilter::extract()
    │  produces
    ▼
BilingualDocument  ──────────────────────────────────────────┐
    │  contains                                               │
    ▼                                                         │
SegmentPair[]                                                 │
  ├── source: Segment  ◄── SrxSegmentationEngine::segment()  │
  ├── target: Segment  ◄── TranslationMemory::lookup()       │
  ├── state: SegmentState                                     │
  └── isLocked: bool                                         │
       │                                                      │
       ▼                                                      │
  QualityRunner::run()   ← per-pair QA checks                │
  TerminologyProvider::recognize()  ← term highlighting      │
                                                              │
XliffWriter::write()  ◄──────────────────────────────────────┘
    │  produces
    ▼
project.xlf  +  project.xlf.skl (skeleton)
    │
    ▼
XliffReader::read()  →  BilingualDocument  →  FileFilter::rebuild()
```

## Quick start

### 1. Extract a plain text file

```php
use CatFramework\FilterPlaintext\PlainTextFilter;

$filter = new PlainTextFilter();
$doc = $filter->extract('article.txt', 'en-US', 'fr-FR');

foreach ($doc->getSegmentPairs() as $pair) {
    echo $pair->source->getPlainText() . PHP_EOL;
}
```

### 2. Segment with SRX rules

```php
use CatFramework\Segmentation\SrxSegmentationEngine;

$engine = new SrxSegmentationEngine();
// Auto-loads bundled SRX rules for English, Hindi, Urdu, Arabic, French, German, Spanish, CJK

foreach ($doc->getSegmentPairs() as $pair) {
    $sentences = $engine->segment($pair->source, 'en-US');
}
```

### 3. Look up translation memory

```php
use CatFramework\TranslationMemory\SqliteTranslationMemory;

$tm = new SqliteTranslationMemory('project.db');
$tm->import('my-memory.tmx');

foreach ($doc->getSegmentPairs() as $pair) {
    $matches = $tm->lookup($pair->source->getPlainText(), 'en-US', 'fr-FR');
    if (!empty($matches)) {
        echo $matches[0]->score . ' — ' . $matches[0]->targetText . PHP_EOL;
    }
}
```

### 4. Recognise terminology

```php
use CatFramework\Terminology\Provider\SqliteTerminologyProvider;

$terms = new SqliteTerminologyProvider('terms.db');
$terms->import('glossary.tbx');  // TBX v2

$matches = $terms->recognize('Use the translation memory for consistency.', 'en', 'fr');
foreach ($matches as $match) {
    echo "{$match->entry->sourceTerm} → {$match->entry->targetTerm}" . PHP_EOL;
}
```

### 5. Run QA checks

```php
use CatFramework\Qa\QualityRunner;
use CatFramework\Qa\Check\TagConsistencyCheck;
use CatFramework\Qa\Check\NumberConsistencyCheck;
use CatFramework\Qa\Check\EmptyTranslationCheck;

$runner = new QualityRunner();
$runner->register(new TagConsistencyCheck());
$runner->register(new NumberConsistencyCheck());
$runner->register(new EmptyTranslationCheck());

$issues = $runner->run($doc);
foreach ($issues as $issue) {
    echo "[{$issue->severity->name}] {$issue->message}" . PHP_EOL;
}
```

### 6. Export to XLIFF 1.2 and rebuild

```php
use CatFramework\Xliff\XliffWriter;
use CatFramework\Xliff\XliffReader;

$writer = new XliffWriter();
$writer->write($doc, 'project.xlf');
// Also writes project.xlf.skl

$reader = new XliffReader();
$translated = $reader->read('project.xlf');

$filter->rebuild($translated, 'article_fr.docx');
```

## Inline code handling

Inline codes (bold, links, line breaks, formatting) are preserved through the full pipeline as `InlineCode` objects. They survive segmentation, XLIFF serialization, and file rebuild.

When the segmenter splits a sentence at a tag boundary, the spanning tag is automatically:
- marked `isIsolated = true`
- given a synthetic closing tag at the segment end
- given a synthetic opening tag at the start of the next segment

This maps directly to XLIFF 1.2 `<it pos="open|close">` elements.

## Translation memory matching

`SqliteTranslationMemory` provides two match tiers:

| Score | Type | Meaning |
|---|---|---|
| 1.0 | `EXACT` | Identical text and identical inline codes |
| 0.99 | `EXACT_TEXT` | Identical text, codes differ |
| 0.7–0.99 | Fuzzy | Character-level Levenshtein on plain text |

Fuzzy matching uses a character-count pre-filter (±30% of source length) to avoid scanning the full TM for every segment. For ASCII strings PHP's native `levenshtein()` is used; for multibyte text (Hindi, Urdu, Arabic) grapheme-cluster arrays via `ext-intl` ensure correct edit distance.

## Terminology recognition

`SqliteTerminologyProvider` scans running text for known terms using `mb_strpos` with Unicode word-boundary detection (space/punctuation, not regex `\b`, which is byte-level and breaks for Arabic/Devanagari).

TBX v2 (ISO 30042) import is supported. Terms with `administrativeStatus = deprecatedTerm` or `supersededTerm` are stored as forbidden and flagged by `TerminologyConsistencyCheck`.

## Running tests

Each package has its own `phpunit.xml`. From any package directory:

```bash
composer install
php vendor/bin/phpunit
```

## Languages supported (bundled SRX rules)

- English (`EN.*`)
- Hindi (`HI.*`) — Devanagari Purna Viram `।`
- Urdu (`UR.*`) — Arabic Full Stop `۔`
- Arabic (`AR.*`)
- French (`FR.*`)
- German (`DE.*`)
- Spanish (`ES.*`)
- Chinese / Japanese (`ZH.*`, `JA.*`)

## License

MIT
