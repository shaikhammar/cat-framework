<?php

declare(strict_types=1);

namespace CatFramework\FilterHtml;

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

class HtmlFilter implements FileFilterInterface
{
    private const array BLOCK_ELEMENTS = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'li', 'td', 'th', 'dt', 'dd', 'blockquote', 'figcaption', 'caption',
    ];

    private const array INLINE_ELEMENTS = [
        'b', 'strong', 'i', 'em', 'a', 'span', 'sub', 'sup',
        'code', 'abbr', 'u', 'small', 'mark',
    ];

    private const array VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    // State for a single extract() call; reset at the top of extract().
    private array $pairs  = [];
    private array $segMap = []; // segId => token string
    private int   $seqNo  = 1;

    public function supports(string $filePath, ?string $mimeType = null): bool
    {
        return in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['html', 'htm'], true);
    }

    public function getSupportedExtensions(): array
    {
        return ['.html', '.htm'];
    }

    public function extract(string $filePath, string $sourceLanguage, string $targetLanguage): BilingualDocument
    {
        if (!file_exists($filePath)) {
            throw new FilterException("File not found: {$filePath}");
        }

        $html = file_get_contents($filePath);
        if ($html === false) {
            throw new FilterException("Cannot read file: {$filePath}");
        }

        $this->pairs  = [];
        $this->segMap = [];
        $this->seqNo  = 1;

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // The <?xml encoding> PI tells libxml to treat the content as UTF-8
        // without requiring a <meta charset> tag in the HTML.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body instanceof DOMElement) {
            $this->walkElement($body, $dom);
        }

        // Strip the encoding PI that loadHTML adds to the serialized output
        $skeleton = preg_replace('/^<\?[^>]+>\n?/', '', $dom->saveHTML());

        $document = new BilingualDocument(
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            originalFile: basename($filePath),
            mimeType: 'text/html',
            skeleton: ['html' => $skeleton, 'seg_map' => $this->segMap],
        );

        foreach ($this->pairs as $pair) {
            $document->addSegmentPair($pair);
        }

        return $document;
    }

    public function rebuild(BilingualDocument $document, string $outputPath): void
    {
        $html   = $document->skeleton['html'];
        $segMap = $document->skeleton['seg_map']; // segId => token

        foreach ($document->getSegmentPairs() as $pair) {
            $token    = $segMap[$pair->source->id];
            $segment  = $pair->target ?? $pair->source;
            $html     = str_replace($token, $this->renderSegment($segment), $html);
        }

        if (file_put_contents($outputPath, $html) === false) {
            throw new FilterException("Cannot write output file: {$outputPath}");
        }
    }

    private function walkElement(DOMElement $element, DOMDocument $dom): void
    {
        $tag = strtolower($element->nodeName);

        if (in_array($tag, self::BLOCK_ELEMENTS, true)) {
            if ($this->hasBlockChildren($element)) {
                // Container block: recurse into block children only
                foreach (iterator_to_array($element->childNodes) as $child) {
                    if ($child instanceof DOMElement) {
                        $this->walkElement($child, $dom);
                    }
                }
            } else {
                // Leaf block: extract its content as a segment
                $codeSeq  = 1;
                $elements = $this->extractChildNodes($element, $codeSeq);
                $plain    = implode('', array_filter($elements, fn($e) => is_string($e)));

                if (trim($plain) === '') {
                    return; // whitespace-only or code-only block: skip
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
            }
        } else {
            // Structural element (body, section, article, header, nav, ul, ol, table, …): recurse
            foreach (iterator_to_array($element->childNodes) as $child) {
                if ($child instanceof DOMElement) {
                    $this->walkElement($child, $dom);
                }
            }
        }
    }

    private function hasBlockChildren(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), self::BLOCK_ELEMENTS, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recursively walks a node's children and builds the mixed string/InlineCode
     * elements array that will become the source Segment's content.
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
                $tag = strtolower($child->nodeName);

                if (in_array($tag, self::VOID_ELEMENTS, true)) {
                    $codeId     = $tag . $codeSeq++;
                    $elements[] = new InlineCode(
                        id: $codeId,
                        type: InlineCodeType::STANDALONE,
                        data: $this->serializeOpenTag($child),
                        displayText: "<{$tag}/>",
                    );
                } elseif (in_array($tag, self::INLINE_ELEMENTS, true) || !in_array($tag, self::BLOCK_ELEMENTS, true)) {
                    // Known inline element, or unknown non-block element: wrap as paired InlineCodes
                    $codeId   = $tag . $codeSeq++;
                    $openTag  = $this->serializeOpenTag($child);
                    $closeTag = "</{$tag}>";

                    $elements[] = new InlineCode(
                        id: $codeId,
                        type: InlineCodeType::OPENING,
                        data: $openTag,
                        displayText: "<{$tag}>",
                    );
                    $elements   = array_merge($elements, $this->extractChildNodes($child, $codeSeq));
                    $elements[] = new InlineCode(
                        id: $codeId,
                        type: InlineCodeType::CLOSING,
                        data: $closeTag,
                        displayText: $closeTag,
                    );
                }
                // Block element inside inline context (invalid HTML): skip
            }
        }

        return $elements;
    }

    private function serializeOpenTag(DOMElement $element): string
    {
        $tag    = strtolower($element->nodeName);
        $result = "<{$tag}";

        foreach ($element->attributes as $attr) {
            $result .= ' ' . $attr->name . '="' . htmlspecialchars($attr->value, ENT_QUOTES | ENT_HTML5) . '"';
        }

        return $result . '>';
    }

    /**
     * Converts a Segment back to an HTML string for insertion into the skeleton.
     * Text content is entity-encoded; InlineCode data is emitted as raw HTML.
     */
    private function renderSegment(Segment $segment): string
    {
        $html = '';
        foreach ($segment->getElements() as $element) {
            $html .= is_string($element)
                ? htmlspecialchars($element, ENT_QUOTES | ENT_HTML5)
                : $element->data;
        }
        return $html;
    }
}
