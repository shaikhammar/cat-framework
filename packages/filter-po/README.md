# catframework/filter-po

GNU gettext PO file filter for the [CAT Framework](https://github.com/shaikhammar/cat-framework).

## Installation

```bash
composer require catframework/filter-po
```

## Usage

```php
use CatFramework\FilterPo\PoFilter;

$filter = new PoFilter();

// Extract translatable segments from a PO file
$document = $filter->extract('messages.po', 'en', 'fr');

foreach ($document->getSegmentPairs() as $pair) {
    echo $pair->source->getPlainText() . PHP_EOL;
    // … send to MT, TM lookup, or human translator …
    $pair->target = new Segment('seg-t', [$translatedText]);
}

// Write the translated PO file
$filter->rebuild($document, 'messages.fr.po');
```

## What gets extracted

| Entry type | Behaviour |
|---|---|
| Normal `msgid` / `msgstr` | Extracted as a segment |
| Header (empty `msgid`) | Skipped — preserved verbatim in output |
| `#, fuzzy` entries | Skipped — preserved verbatim in output |
| Plural forms (`msgid_plural`) | Singular `msgid` extracted; translation written to `msgstr[0]`; remaining plural slots (`msgstr[1..n]`) set to empty string |
| Multi-line strings | Continuation lines concatenated and decoded |

PO escape sequences (`\n`, `\t`, `\"`, `\\`) are decoded on extraction and re-encoded on rebuild.

## Skeleton format

The skeleton stored in `BilingualDocument::$skeleton` is:

```php
[
    'lines'   => string[],  // full file split by "\n", msgstr blocks replaced by tokens
    'seg_map' => [          // segId => token string
        'seg-1' => '{{SEG:001}}',
        'seg-2' => '{{SEG:002}}',
        // …
    ],
]
```

## Limitations

- Plural translations: only `msgstr[0]` is filled; `msgstr[1..n]` require manual post-editing.
- `msgctxt` (message context) is recognised and skipped over during parsing but not exposed as metadata on the segment.
- Binary PO files (`.mo`) are not supported.
