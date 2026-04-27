# catframework/filter-html

HTML file filter for the [CAT Framework](https://github.com/shaikhammar/cat-framework).

## Installation

```bash
composer require catframework/filter-html
```

Requires `ext-dom` and `ext-libxml`.

## Usage

```php
use CatFramework\FilterHtml\HtmlFilter;

$filter = new HtmlFilter();

// Extract translatable segments
$document = $filter->extract('page.html', 'en', 'fr');

foreach ($document->getSegmentPairs() as $pair) {
    $pair->target = new Segment('seg-t', [$translatedText]);
}

// Write the translated file
$filter->rebuild($document, 'page.fr.html');
```

## What gets extracted

The filter uses a **block element** taxonomy to decide segmentation boundaries:

**Block elements** (each becomes at most one segment): `<p>`, `<div>`, `<h1>`–`<h6>`, `<li>`, `<td>`, `<th>`, `<dt>`, `<dd>`, `<blockquote>`, `<figcaption>`, `<caption>`

- A block element with only text / inline children → extracted as one segment.
- A block element that itself contains other block elements → recursed into (not extracted as a whole).

**Inline elements** inside a segment become `InlineCode` pairs so translators see placeholders rather than raw HTML tags: `<b>`, `<strong>`, `<i>`, `<em>`, `<a>`, `<span>`, `<sub>`, `<sup>`, `<code>`, `<abbr>`, `<u>`, `<small>`, `<mark>`

**Void elements** (`<br>`, `<img>`, `<input>`, etc.) become standalone `InlineCode` placeholders.

**Whitespace-only** blocks are silently skipped.

## Skeleton format

```php
[
    'html'    => string,   // serialized DOMDocument with {{SEG:NNN}} tokens in place of block content
    'seg_map' => [         // segId => token string
        'seg-1' => '{{SEG:001}}',
        // …
    ],
]
```

## Limitations

- **Full HTML documents only**: the filter expects a `<body>` element. Fragment-only strings (no wrapping body) will produce no segments.
- **Structural elements outside the body** (`<head>`, `<title>`, `<meta>`) are not extracted.
- **Unknown non-block elements** are treated as inline and wrapped as `InlineCode` pairs.
- **Invalid nesting** (block element inside an inline context) is silently ignored.
