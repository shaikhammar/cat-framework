# catframework/filter-plaintext

Plain text (`.txt`) file filter for the [CAT Framework](https://github.com/shaikhammar/cat-framework).

## Installation

```bash
composer require catframework/filter-plaintext
```

## Usage

```php
use CatFramework\FilterPlaintext\PlainTextFilter;

$filter = new PlainTextFilter();

// Extract translatable segments
$document = $filter->extract('article.txt', 'en', 'fr');

foreach ($document->getSegmentPairs() as $pair) {
    $pair->target = new Segment('seg-t', [$translatedText]);
}

// Write the translated file
$filter->rebuild($document, 'article.fr.txt');
```

## How segments are split

The filter splits on **two or more consecutive newlines** (blank-line paragraph breaks). Each non-whitespace block becomes one segment. Single newlines within a block are preserved as-is and are part of the segment text.

```
First paragraph.       → segment 1
                       → (separator, not a segment)
Second paragraph.      → segment 2

Third paragraph.       → segment 3
```

Whitespace-only blocks (e.g. multiple blank lines between paragraphs) are passed through unchanged and do not become segments.

## Encoding

Input files are auto-detected as UTF-8, ISO-8859-1, or Windows-1252. All output is written in UTF-8. If encoding detection fails, the file is treated as UTF-8.

## Skeleton format

```php
[
    'parts'   => string[],      // file split by paragraph boundaries, separators included
    'seg_map' => [int => string], // parts array index => segId
]
```

## Limitations

- No inline markup support — the entire segment is plain text; no `InlineCode` elements are produced.
- No sentence-level segmentation — each paragraph is one segment regardless of length. Use `catframework/segmentation` for sentence splitting.
- Encoding detection relies on `mb_detect_encoding`; unusual encodings (e.g. Shift-JIS) are not supported.
