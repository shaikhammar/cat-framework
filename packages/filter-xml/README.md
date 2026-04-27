# catframework/filter-xml

Generic XML file filter for the [CAT Framework](https://github.com/shaikhammar/cat-framework).

Works with any well-formed XML file: Android string resources, app config files,
custom XML formats, etc.

## Installation

```bash
composer require catframework/filter-xml
```

## Usage

```php
use CatFramework\FilterXml\XmlFilter;

$filter = new XmlFilter();

// Extract translatable segments from an XML file
$document = $filter->extract('strings.xml', 'en', 'fr');

foreach ($document->getSegmentPairs() as $pair) {
    echo $pair->source->getPlainText() . PHP_EOL;
    // … send to MT, TM lookup, or human translator …
    $pair->target = new Segment('seg-t', [$translatedText]);
}

// Write the translated XML file
$filter->rebuild($document, 'strings.fr.xml');
```

## Extraction heuristic

The filter uses a structural heuristic to decide what to extract:

- **Translatable element** — has at least one non-whitespace direct text node. Its full content (text + any child elements) is extracted as one segment.
- **Container element** — has only element children. Recursed into; not extracted itself.

Child elements inside a translatable segment are represented as `InlineCode` pairs so translators see placeholders (`<b>`, `</b>`) rather than raw markup.

**Example** — given:

```xml
<resources>
    <string name="greeting">Hello <b>world</b></string>
    <container>
        <item>First item</item>
    </container>
</resources>
```

Three segments are extracted: `Hello {<b>}world{</b>}`, `First item`.

## Skeleton format

The skeleton stored in `BilingualDocument::$skeleton` is:

```php
[
    'xml'     => string,    // full DOMDocument::saveXML() output with tokens in place of segment text
    'seg_map' => [          // segId => token string
        'seg-1' => '{{SEG:001}}',
        'seg-2' => '{{SEG:002}}',
        // …
    ],
]
```

Tokens are valid XML character data, so the skeleton is always parseable XML.

## Limitations

- **Generic heuristic**: the filter has no knowledge of application-specific schemas. Elements that should not be translated (e.g. `<version>`, `<id>`) will be extracted if they contain text. For schema-aware extraction, subclass `XmlFilter` and override `walkElement()`.
- **Whitespace-only nodes**: text nodes containing only whitespace (indentation, newlines) are silently skipped.
- **CDATA sections**: treated as text content by the DOM; extracted and re-encoded as regular text on rebuild.
- **XML namespace prefixes** are preserved in `InlineCode` data as-is.
