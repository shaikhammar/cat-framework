# CAT Framework

An open-source, modular PHP framework for building computer-assisted translation (CAT) tools. Framework-agnostic, no Laravel dependency, designed for PHP 8.2+ with full UTF-8 and RTL support from day one.

## Packages

| Package | Description |
|---|---|
| [`catframework/core`](packages/core) | Shared data models, contracts, and enums |
| [`catframework/srx`](packages/srx) | SRX 2.0 segmentation rule parser |
| [`catframework/segmentation`](packages/segmentation) | SRX-based sentence segmentation engine |
| [`catframework/filter-plaintext`](packages/filter-plaintext) | Plain text file filter (`.txt`) |
| [`catframework/filter-html`](packages/filter-html) | HTML file filter (`.html`, `.htm`) |
| [`catframework/xliff`](packages/xliff) | XLIFF 1.2 writer and reader |

## Requirements

- PHP 8.2+
- `ext-mbstring` (segmentation, filter-plaintext)
- `ext-dom` + `ext-libxml` (srx, filter-html, xliff)

## How it fits together

```
Source file
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
  ├── target: Segment  ◄── translator fills this in          │
  ├── state: SegmentState                                     │
  └── isLocked: bool                                         │
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
    // Each sentence is a Segment with text and inline codes preserved
}
```

### 3. Export to XLIFF 1.2

```php
use CatFramework\Xliff\XliffWriter;
use CatFramework\Xliff\XliffReader;

$writer = new XliffWriter();
$writer->write($doc, 'project.xlf');
// Also writes project.xlf.skl (skeleton for file rebuild)

// After translation, read back
$reader = new XliffReader();
$translated = $reader->read('project.xlf');
```

### 4. Rebuild the original file

```php
$filter->rebuild($translated, 'article_fr.txt');
```

## Inline code handling

Inline codes (bold, links, line breaks, etc.) are preserved through the full pipeline as `InlineCode` objects — never lost or mangled. They survive segmentation, XLIFF serialization, and file rebuild.

When the segmenter splits a sentence at a tag boundary, the spanning tag is automatically:
- marked `isIsolated = true`
- given a synthetic closing tag at the segment end
- given a synthetic opening tag at the start of the next segment

This maps directly to XLIFF 1.2 `<it pos="open|close">` elements.

## Running tests

Each package has its own `phpunit.xml`. From any package directory:

```bash
composer install
php vendor/phpunit/phpunit/phpunit
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
