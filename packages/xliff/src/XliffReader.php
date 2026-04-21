<?php

declare(strict_types=1);

namespace CatFramework\Xliff;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Enum\SegmentState;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;
use DOMElement;
use DOMText;
use DOMXPath;

class XliffReader
{
    private const CATFW_NS = 'urn:catframework';

    public function read(string $xliffPath): BilingualDocument
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);

        if (!$dom->load($xliffPath)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $msg = $errors ? $errors[0]->message : 'unknown error';
            throw new XliffException("Cannot load XLIFF '{$xliffPath}': " . trim($msg));
        }

        $xpath = new DOMXPath($dom);

        $fileEl = $xpath->query('//*[local-name()="file"]')->item(0);
        if (!$fileEl instanceof DOMElement) {
            throw new XliffException("No <file> element found in '{$xliffPath}'");
        }

        $originalFile   = $fileEl->getAttribute('original');
        $sourceLanguage = $fileEl->getAttribute('source-language');
        $targetLanguage = $fileEl->getAttribute('target-language');
        $mimeType       = $this->datatypeToMime($fileEl->getAttribute('datatype'));

        $skeleton = $this->loadSkeleton($xliffPath, $xpath);

        $doc   = new BilingualDocument($sourceLanguage, $targetLanguage, $originalFile, $mimeType, [], $skeleton);
        $units = $xpath->query('//*[local-name()="trans-unit"]');

        foreach ($units as $unit) {
            if (!$unit instanceof DOMElement) {
                continue;
            }

            $id       = $unit->getAttribute('id');
            $isLocked = $unit->getAttribute('translate') === 'no';

            $sourceEl = $xpath->query('*[local-name()="source"]', $unit)->item(0);
            $targetEl = $xpath->query('*[local-name()="target"]', $unit)->item(0);

            $source = $this->parseSegment($id, $sourceEl instanceof DOMElement ? $sourceEl : null);
            $target = $targetEl instanceof DOMElement ? $this->parseSegment($id, $targetEl) : null;
            $state  = $targetEl instanceof DOMElement
                ? $this->xliffToState($targetEl->getAttribute('state'))
                : SegmentState::INITIAL;

            // Treat an empty target the same as no target
            if ($target !== null && $target->isEmpty()) {
                $target = null;
            }

            $doc->addSegmentPair(new SegmentPair($source, $target, $state, $isLocked));
        }

        return $doc;
    }

    private function loadSkeleton(string $xliffPath, DOMXPath $xpath): array
    {
        $extFileEl = $xpath->query('//*[local-name()="external-file"]')->item(0);
        if (!$extFileEl instanceof DOMElement) {
            return [];
        }

        $href    = $extFileEl->getAttribute('href');
        $sklPath = dirname($xliffPath) . '/' . $href;

        if (!file_exists($sklPath)) {
            return [];
        }

        return json_decode(file_get_contents($sklPath), true, 512, JSON_THROW_ON_ERROR);
    }

    private function parseSegment(string $id, ?DOMElement $el): Segment
    {
        if ($el === null) {
            return new Segment($id);
        }

        $elements = [];

        foreach ($el->childNodes as $node) {
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

        return new Segment($id, $elements);
    }

    private function parseInlineElement(DOMElement $el): ?InlineCode
    {
        // rid carries the InlineCode::$id; fall back to the XLIFF element id
        $codeId      = $el->getAttribute('rid') ?: $el->getAttribute('id');
        $data        = $el->textContent;
        $displayText = $el->getAttributeNS(self::CATFW_NS, 'equiv-text') ?: null;

        return match ($el->localName) {
            'bpt'   => new InlineCode($codeId, InlineCodeType::OPENING,    $data, $displayText),
            'ept'   => new InlineCode($codeId, InlineCodeType::CLOSING,    $data, $displayText),
            'ph'    => new InlineCode($codeId, InlineCodeType::STANDALONE,  $data, $displayText),
            'it'    => new InlineCode(
                           $codeId,
                           $el->getAttribute('pos') === 'open' ? InlineCodeType::OPENING : InlineCodeType::CLOSING,
                           $data,
                           $displayText,
                           true,
                       ),
            default => null,
        };
    }

    private function datatypeToMime(string $datatype): string
    {
        return match ($datatype) {
            'plaintext' => 'text/plain',
            'html'      => 'text/html',
            default     => 'application/octet-stream',
        };
    }

    private function xliffToState(string $state): SegmentState
    {
        return match ($state) {
            'translated' => SegmentState::TRANSLATED,
            'signed-off' => SegmentState::REVIEWED,
            'final'      => SegmentState::FINAL,
            default      => SegmentState::INITIAL,
        };
    }
}
