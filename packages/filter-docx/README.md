# catframework/filter-docx

Microsoft Word DOCX file filter for the [CAT Framework](https://github.com/shaikhammar/cat-framework).

## Installation

```bash
composer require catframework/filter-docx
```

Requires `ext-dom`, `ext-libxml`, and `ext-zip`.

## Usage

```php
use CatFramework\FilterDocx\DocxFilter;

$filter = new DocxFilter();

// Extract translatable segments
$document = $filter->extract('report.docx', 'en', 'fr');

foreach ($document->getSegmentPairs() as $pair) {
    $pair->target = new Segment('seg-t', [$translatedText]);
}

// Write the translated DOCX
$filter->rebuild($document, 'report.fr.docx');
```

## What gets extracted

Each non-empty `<w:p>` paragraph in the document is one segment. Adjacent runs with identical formatting (`<w:rPr>`) are merged before extraction, reducing the number of inline code placeholders a translator sees.

**Extracted locations** (in order):
1. `word/document.xml` — main body
2. `word/header1.xml` … `word/header10.xml` — headers
3. `word/footer1.xml` … `word/footer10.xml` — footers
4. `word/footnotes.xml`, `word/endnotes.xml` — notes

Formatting runs within a paragraph become `InlineCode` pairs so translators see `{<bold>}translated text{</bold>}` instead of raw XML.

## RTL support

When the target language is Arabic, Hebrew, Farsi, Urdu, or another RTL language, `<w:rtl/>` is injected into each run's `<w:rPr>` and `<w:bidi/>` is added to paragraph properties on rebuild.

Supported RTL language prefixes: `ar`, `he`, `fa`, `ur`, `yi`, `dv`, `ps`, `sd`.

## Skeleton format

The skeleton is a temporary DOCX file written to the system temp directory at extract time:

```php
['path' => '/tmp/cat-<uniqid>.skl']
```

The skeleton file is a copy of the original DOCX ZIP with paragraph content replaced by `{{SEG:NNN}}` tokens. **Do not delete it** between `extract()` and `rebuild()` calls. It is not automatically cleaned up.

## Limitations

- **Tables**: cell text is extracted as individual paragraph segments; table structure is preserved in the skeleton.
- **Text boxes and shapes**: content inside drawing anchors is not currently extracted.
- **Comments and revisions**: tracked changes and comment text are not extracted.
- **Skeleton lifetime**: the `.skl` temp file must survive between `extract()` and `rebuild()`. For long-lived workflows, persist `$document->skeleton['path']` and ensure the file is not cleaned up by the OS.
