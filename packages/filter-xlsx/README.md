# catframework/filter-xlsx

Microsoft Excel XLSX file filter for the [CAT Framework](https://github.com/shaikhammar/cat-framework).

## Installation

```bash
composer require catframework/filter-xlsx
```

Requires `ext-dom`, `ext-libxml`, and `ext-zip`.

## Usage

```php
use CatFramework\FilterXlsx\XlsxFilter;

$filter = new XlsxFilter();

// Extract translatable segments
$document = $filter->extract('data.xlsx', 'en', 'fr');

foreach ($document->getSegmentPairs() as $pair) {
    $pair->target = new Segment('seg-t', [$translatedText]);
}

// Write the translated XLSX
$filter->rebuild($document, 'data.fr.xlsx');
```

## What gets extracted

XLSX stores cell text in two places; the filter handles both:

| Storage type | Location in ZIP | Notes |
|---|---|---|
| **Shared strings** | `xl/sharedStrings.xml` | Deduplicated across the workbook; only strings actually referenced by cells are extracted |
| **Inline strings** | `xl/worksheets/sheet*.xml` (cells with `t="inlineStr"`) | Extracted and replaced per cell |

**Non-translatable strings are skipped automatically**: pure numbers, currency values, percentages, and empty strings are detected by a regex heuristic and omitted from extraction.

Rich-text shared strings (multiple `<r>` runs with different formatting) preserve their formatting as `InlineCode` pairs on the segment.

On rebuild, `xl/calcChain.xml` is deleted so Excel recomputes formula dependencies on next open (avoids stale cell reference errors).

## Skeleton format

The skeleton is a temporary XLSX file written to the system temp directory at extract time:

```php
['path' => '/tmp/cat-<uniqid>.skl']
```

The skeleton is a copy of the original XLSX ZIP with translatable cell values replaced by `{{SEG:NNN}}` tokens. **Do not delete it** between `extract()` and `rebuild()` calls.

## Limitations

- **Formula cells**: cells containing formulas (`=SUM(...)`) are not extracted — only their stored text values if present.
- **Number-format strings**: strings that look purely numeric (digits, commas, currency symbols, `%`) are silently skipped. If a string like `"1,234 units"` should be translatable, it will be skipped due to the numeric heuristic.
- **Worksheet names**: tab names are not extracted.
- **Skeleton lifetime**: the `.skl` temp file must survive between `extract()` and `rebuild()`. For long-lived workflows, persist `$document->skeleton['path']`.
