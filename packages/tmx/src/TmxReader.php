<?php

declare(strict_types=1);

namespace CatFramework\Tmx;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\TranslationUnit;
use DOMElement;
use DOMText;

class TmxReader
{
    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    /**
     * DOM mode — loads the entire file into memory.
     * Suitable for files with up to ~10,000 TUs.
     *
     * @return TranslationUnit[]
     */
    public function read(string $tmxPath): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);

        if (!$dom->load($tmxPath)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $msg = $errors ? $errors[0]->message : 'unknown error';
            throw new TmxException("Cannot load TMX '{$tmxPath}': " . trim($msg));
        }

        $header = $dom->getElementsByTagName('header')->item(0);
        if (!$header instanceof DOMElement) {
            throw new TmxException("Missing <header> in '{$tmxPath}'");
        }

        $srcLang = $header->getAttribute('srclang');
        $units   = [];

        foreach ($dom->getElementsByTagName('tu') as $tu) {
            if ($tu instanceof DOMElement) {
                $units[] = $this->parseTuElement($tu, $srcLang);
            }
        }

        return $units;
    }

    /**
     * Streaming mode — yields one TranslationUnit at a time via XMLReader.
     * Suitable for large files (100k+ TUs) without loading the full DOM.
     *
     * @return \Generator<TranslationUnit>
     */
    public function stream(string $tmxPath): \Generator
    {
        $reader = new \XMLReader();

        if (!@$reader->open($tmxPath)) {
            throw new TmxException("Cannot open TMX for streaming: '{$tmxPath}'");
        }

        // Advance to <header> and read srclang before yielding any TUs
        $srcLang = '*all*';
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'header') {
                $srcLang = $reader->getAttribute('srclang') ?? '*all*';
                break;
            }
        }

        // Validate header was found
        if ($reader->localName !== 'header') {
            $reader->close();
            throw new TmxException("Missing <header> in '{$tmxPath}'");
        }

        // Stream TUs
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'tu') {
                continue;
            }

            // expand() without argument creates a fresh DOMNode per TU — no memory accumulation
            $node = $reader->expand();
            if ($node instanceof DOMElement) {
                yield $this->parseTuElement($node, $srcLang);
            }
        }

        $reader->close();
    }

    private function parseTuElement(DOMElement $tu, string $srcLang): TranslationUnit
    {
        $tuid      = $tu->getAttribute('tuid') ?: uniqid('tu-');
        $createdBy = $tu->getAttribute('creationid') ?: null;
        $createdAt = $this->parseDate($tu->getAttribute('creationdate'));

        $metadata    = [];
        $tuvsByLang  = [];

        foreach ($tu->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            switch ($child->localName) {
                case 'prop':
                    $type = $child->getAttribute('type');
                    $metadata[$type] = $child->textContent;
                    break;

                case 'note':
                    $metadata['notes'][] = $child->textContent;
                    break;

                case 'tuv':
                    $lang = $child->getAttributeNS(self::XML_NS, 'lang')
                         ?: $child->getAttribute('xml:lang');
                    if ($lang !== '') {
                        $tuvsByLang[$lang] = $child;
                    }
                    break;
            }
        }

        if (count($tuvsByLang) < 2) {
            throw new TmxException("TU '{$tuid}' has fewer than 2 language variants");
        }

        // Determine source tuv: match srclang, otherwise first tuv
        $langs      = array_keys($tuvsByLang);
        $sourceLang = $this->matchLang($srcLang, $langs) ?? $langs[0];
        $targetLang = current(array_filter($langs, fn($l) => $l !== $sourceLang));

        // Extract lastUsedAt from custom prop and remove from metadata
        $lastUsedAt = null;
        if (isset($metadata['x-lastUsedAt'])) {
            $lastUsedAt = $this->parseDate($metadata['x-lastUsedAt']);
            unset($metadata['x-lastUsedAt']);
        }

        return new TranslationUnit(
            source:         $this->parseTuv($tuvsByLang[$sourceLang], $tuid . '-src'),
            target:         $this->parseTuv($tuvsByLang[$targetLang], $tuid . '-tgt'),
            sourceLanguage: $sourceLang,
            targetLanguage: $targetLang,
            createdAt:      $createdAt,
            lastUsedAt:     $lastUsedAt,
            createdBy:      $createdBy !== '' ? $createdBy : null,
            metadata:       $metadata,
        );
    }

    private function parseTuv(DOMElement $tuv, string $segId): Segment
    {
        foreach ($tuv->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'seg') {
                return new Segment($segId, $this->parseSegContent($child));
            }
        }

        return new Segment($segId);
    }

    private function parseSegContent(DOMElement $seg): array
    {
        $elements = [];

        foreach ($seg->childNodes as $node) {
            if ($node instanceof DOMText) {
                $text = $node->nodeValue;
                if ($text !== '' && $text !== null) {
                    $elements[] = $text;
                }
            } elseif ($node instanceof DOMElement) {
                $code = $this->parseInlineElement($node);
                if ($code !== null) {
                    $elements[] = $code;
                }
            }
        }

        return $elements;
    }

    private function parseInlineElement(DOMElement $el): ?InlineCode
    {
        // TMX uses `i` attribute as the pairing key (like XLIFF's `rid`)
        $id   = $el->getAttribute('i') ?: $el->getAttribute('id') ?: '0';
        $data = $el->textContent;

        return match ($el->localName) {
            'bpt'   => new InlineCode($id, InlineCodeType::OPENING,    $data),
            'ept'   => new InlineCode($id, InlineCodeType::CLOSING,    $data),
            'ph'    => new InlineCode($id, InlineCodeType::STANDALONE,  $data),
            'it'    => new InlineCode(
                           $id,
                           $el->getAttribute('pos') === 'begin' ? InlineCodeType::OPENING : InlineCodeType::CLOSING,
                           $data,
                           null,
                           true,
                       ),
            default => null,
        };
    }

    /**
     * Find the best language match from a list (exact, then prefix).
     * Returns null if no match found.
     */
    private function matchLang(string $srclang, array $langs): ?string
    {
        if ($srclang === '*all*') {
            return null;
        }

        // Exact match
        foreach ($langs as $lang) {
            if (strcasecmp($lang, $srclang) === 0) {
                return $lang;
            }
        }

        // Prefix match (e.g. header srclang="en" matches tuv xml:lang="en-US")
        $prefix = strtolower(explode('-', $srclang)[0]);
        foreach ($langs as $lang) {
            if (strtolower(explode('-', $lang)[0]) === $prefix) {
                return $lang;
            }
        }

        return null;
    }

    private function parseDate(string $dateStr): \DateTimeImmutable
    {
        if ($dateStr === '') {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        $dt = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $dateStr, new \DateTimeZone('UTC'))
           ?: new \DateTimeImmutable($dateStr);

        return $dt;
    }
}
