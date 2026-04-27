# catframework/filter-pptx

Microsoft PowerPoint PPTX file filter for the [CAT Framework](https://github.com/shaikhammar/cat-framework).

## Installation

```bash
composer require catframework/filter-pptx
```

Requires `ext-dom`, `ext-libxml`, and `ext-zip`.

## Usage

```php
use CatFramework\FilterPptx\PptxFilter;

$filter = new PptxFilter();

// Extract translatable segments
$document = $filter->extract('slides.pptx', 'en', 'fr');

foreach ($document->getSegmentPairs() as $pair) {
    $pair->target = new Segment('seg-t', [$translatedText]);
}

// Write the translated PPTX
$filter->rebuild($document, 'slides.fr.pptx');
```

## What gets extracted

Each non-empty `<a:p>` paragraph in the presentation is one segment. Slide order follows `ppt/_rels/presentation.xml.rels`, which preserves the authoring application's declared order.

**Extracted locations** (per slide, in order):
1. Slide content (`ppt/slides/slideN.xml`)
2. Speaker notes (`ppt/notesSlides/notesSlideN.xml`), if present

**Hidden slides** (marked `show="0"` on the root `<p:sld>` element) are silently skipped.

Adjacent runs with identical DrawingML formatting (`<a:rPr>`) are merged before extraction. Remaining formatting runs become `InlineCode` pairs.

## RTL support

When the target language is a right-to-left language, `rtl="1"` is set on each run's `<a:rPr>` and on the paragraph's `<a:pPr>` on rebuild.

Supported RTL language prefixes: `ar`, `he`, `fa`, `ur`, `yi`, `dv`, `ps`, `sd`.

## Skeleton format

The skeleton is a temporary PPTX file written to the system temp directory at extract time:

```php
['path' => '/tmp/cat-<uniqid>.skl']
```

The skeleton is a copy of the original PPTX ZIP with paragraph content replaced by `{{SEG:NNN}}` tokens. **Do not delete it** between `extract()` and `rebuild()` calls.

## Limitations

- **Charts and SmartArt**: text embedded in chart data or SmartArt XML is not extracted.
- **Embedded objects**: OLE objects and embedded workbooks are not processed.
- **Skeleton lifetime**: the `.skl` temp file must survive between `extract()` and `rebuild()`. For long-lived workflows, persist `$document->skeleton['path']`.
