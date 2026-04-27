<?php

declare(strict_types=1);

namespace CatFramework\FilterXml;

use CatFramework\Core\Contract\FileFilterInterface;
use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Exception\FilterException;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Generic XML file filter.
 *
 * Skeleton strategy: translatable elements' content is replaced with a
 * token "{{SEG:NNN}}" text node in the DOM; the modified DOM is serialized
 * via saveXML() as the skeleton string. rebuild() performs a plain-string
 * replacement of each token with the translated text.
 *
 * Extraction heuristic:
 *   - An element with at least one non-whitespace direct DOMText child is a
 *     translatable segment (its full child content, including nested elements
 *     as InlineCodes, is extracted as one segment).
 *   - An element with only DOMElement children is a container; recurse.
 *
 * Skipped: elements whose direct text is purely whitespace (structural padding).
 */
class XmlFilter implements FileFilterInterface
{
    // Per-call state; reset at the top of extract().
    private array $pairs  = [];
    private array $segMap = []; // segId => token
    private int   $seqNo  = 1;

    public function supports(string $filePath, ?string $mimeType = null): bool
    {
        return strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'xml';
    }

    public function getSupportedExtensions(): array
    {
        return ['.xml'];
    }

    public function extract(string $filePath, string $sourceLanguage, string $targetLanguage): BilingualDocument
    {
        if (!file_exists($filePath)) {
            throw new FilterException("File not found: {$filePath}");
        }

        $xml = file_get_contents($filePath);
        if ($xml === false) {
            throw new FilterException("Cannot read file: {$filePath}");
        }

        $this->pairs  = [];
        $this->segMap = [];
        $this->seqNo  = 1;

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($xml)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $msg = $errors !== [] ? $errors[0]->message : 'unknown';
            throw new FilterException("Invalid XML in {$filePath}: {$msg}");
        }
        libxml_clear_errors();

        if ($dom->documentElement instanceof DOMElement) {
            $this->walkElement($dom->documentElement, $dom);
        }

        $skeleton = $dom->saveXML();

        $document = new BilingualDocument(
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            originalFile: basename($filePath),
            mimeType: 'application/xml',
            skeleton: ['xml' => $skeleton, 'seg_map' => $this->segMap],
        );

        foreach ($this->pairs as $pair) {
            $document->addSegmentPair($pair);
        }

        return $document;
    }

    public function rebuild(BilingualDocument $document, string $outputPath): void
    {
        $xml    = $document->skeleton['xml'];
        $segMap = $document->skeleton['seg_map']; // segId => token

        foreach ($document->getSegmentPairs() as $pair) {
            $token   = $segMap[$pair->source->id];
            $segment = $pair->target ?? $pair->source;
            $xml     = str_replace($token, $this->renderSegment($segment), $xml);
        }

        if (file_put_contents($outputPath, $xml) === false) {
            throw new FilterException("Cannot write output file: {$outputPath}");
        }
    }

    // ── DOM walker ────────────────────────────────────────────────────────────

    private function walkElement(DOMElement $element, DOMDocument $dom): void
    {
        if ($this->hasDirectText($element)) {
            $codeSeq  = 1;
            $elements = $this->extractChildNodes($element, $codeSeq);
            $plain    = implode('', array_filter($elements, fn($e) => is_string($e)));

            if (trim($plain) === '') {
                return;
            }

            $segId = 'seg-' . $this->seqNo;
            $token = '{{SEG:' . str_pad((string) $this->seqNo, 3, '0', STR_PAD_LEFT) . '}}';
            $this->seqNo++;

            while ($element->firstChild) {
                $element->removeChild($element->firstChild);
            }
            $element->appendChild($dom->createTextNode($token));

            $this->segMap[$segId] = $token;
            $this->pairs[]        = new SegmentPair(source: new Segment($segId, $elements));
        } else {
            foreach (iterator_to_array($element->childNodes) as $child) {
                if ($child instanceof DOMElement) {
                    $this->walkElement($child, $dom);
                }
            }
        }
    }

    /** True when the element has at least one non-whitespace direct text node. */
    private function hasDirectText(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->nodeValue) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Builds the mixed string/InlineCode array for a segment.
     * Nested elements become paired InlineCodes; text nodes become plain strings.
     *
     * @return array<string|InlineCode>
     */
    private function extractChildNodes(DOMNode $node, int &$codeSeq): array
    {
        $elements = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                if ($child->nodeValue !== '') {
                    $elements[] = $child->nodeValue;
                }
            } elseif ($child instanceof DOMElement) {
                $tag    = $child->nodeName;
                $codeId = $tag . $codeSeq++;

                $elements[] = new InlineCode(
                    id: $codeId,
                    type: InlineCodeType::OPENING,
                    data: $this->serializeOpenTag($child),
                    displayText: "<{$tag}>",
                );
                $elements   = array_merge($elements, $this->extractChildNodes($child, $codeSeq));
                $elements[] = new InlineCode(
                    id: $codeId,
                    type: InlineCodeType::CLOSING,
                    data: "</{$tag}>",
                    displayText: "</{$tag}>",
                );
            }
        }

        return $elements;
    }

    private function serializeOpenTag(DOMElement $element): string
    {
        $tag    = $element->nodeName;
        $result = "<{$tag}";

        foreach ($element->attributes as $attr) {
            $result .= ' ' . $attr->name . '="' . htmlspecialchars($attr->value, ENT_XML1 | ENT_QUOTES) . '"';
        }

        return $result . '>';
    }

    /**
     * Renders a segment back to an XML-safe string.
     * Text is XML-entity-escaped; InlineCode data is emitted as raw markup.
     */
    private function renderSegment(Segment $segment): string
    {
        $out = '';
        foreach ($segment->getElements() as $element) {
            $out .= is_string($element)
                ? htmlspecialchars($element, ENT_XML1 | ENT_QUOTES)
                : $element->data;
        }
        return $out;
    }
}
