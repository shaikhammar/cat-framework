<?php

declare(strict_types=1);

namespace CatFramework\Xliff;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Enum\SegmentStatus;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;

class XliffWriter
{
    private const XLIFF_NS  = 'urn:oasis:names:tc:xliff:document:1.2';
    private const CATFW_NS  = 'urn:catframework';

    public function write(BilingualDocument $doc, string $xliffPath): void
    {
        $sklPath = $xliffPath . '.skl';
        file_put_contents(
            $sklPath,
            json_encode($doc->skeleton, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        $sklHref  = basename($sklPath);
        $datatype = $this->mimeToDatatype($doc->mimeType);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<xliff version="1.2"';
        $xml .= ' xmlns="'       . self::XLIFF_NS  . '"';
        $xml .= ' xmlns:catfw="' . self::CATFW_NS  . '"';
        $xml .= '>' . "\n";
        $xml .= '  <file';
        $xml .= ' original="'         . $this->esc($doc->originalFile)    . '"';
        $xml .= ' source-language="'  . $this->esc($doc->sourceLanguage)  . '"';
        $xml .= ' target-language="'  . $this->esc($doc->targetLanguage)  . '"';
        $xml .= ' datatype="'         . $datatype                          . '"';
        $xml .= '>' . "\n";
        $xml .= '    <header>' . "\n";
        $xml .= '      <skl><external-file href="' . $this->esc($sklHref) . '"/></skl>' . "\n";
        $xml .= '    </header>' . "\n";
        $xml .= '    <body>' . "\n";

        foreach ($doc->getSegmentPairs() as $pair) {
            $xml .= $this->renderTransUnit($pair);
        }

        $xml .= '    </body>' . "\n";
        $xml .= '  </file>' . "\n";
        $xml .= '</xliff>' . "\n";

        if (file_put_contents($xliffPath, $xml) === false) {
            throw new XliffException("Cannot write XLIFF to: {$xliffPath}");
        }
    }

    private function renderTransUnit(SegmentPair $pair): string
    {
        $id        = $this->esc($pair->source->id);
        $translate = $pair->isLocked ? 'no' : 'yes';
        $state     = $this->statusToXliff($pair->status);

        $tu  = '      <trans-unit id="' . $id . '" translate="' . $translate . '">' . "\n";
        $tu .= '        <source>' . $this->renderSegment($pair->source) . '</source>' . "\n";

        $targetContent = $pair->target !== null ? $this->renderSegment($pair->target) : '';
        $tu .= '        <target state="' . $state . '">' . $targetContent . '</target>' . "\n";
        $tu .= '      </trans-unit>' . "\n";

        return $tu;
    }

    private function renderSegment(Segment $segment): string
    {
        $out     = '';
        $counter = 0;

        foreach ($segment->getElements() as $el) {
            if (is_string($el)) {
                $out .= $this->esc($el);
            } else {
                $out .= $this->renderInlineCode($el, ++$counter);
            }
        }

        return $out;
    }

    private function renderInlineCode(InlineCode $code, int $counter): string
    {
        $rid       = $this->esc($code->id);
        $data      = $this->esc($code->data);
        $equivAttr = $code->displayText !== null
            ? ' catfw:equiv-text="' . $this->esc($code->displayText) . '"'
            : '';

        if ($code->isIsolated) {
            $pos = $code->type === InlineCodeType::OPENING ? 'open' : 'close';
            return "<it id=\"{$counter}\" pos=\"{$pos}\" rid=\"{$rid}\"{$equivAttr}>{$data}</it>";
        }

        return match ($code->type) {
            InlineCodeType::OPENING    => "<bpt id=\"{$counter}\" rid=\"{$rid}\"{$equivAttr}>{$data}</bpt>",
            InlineCodeType::CLOSING    => "<ept id=\"{$counter}\" rid=\"{$rid}\"{$equivAttr}>{$data}</ept>",
            InlineCodeType::STANDALONE => "<ph id=\"{$counter}\" rid=\"{$rid}\"{$equivAttr}>{$data}</ph>",
        };
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function mimeToDatatype(string $mimeType): string
    {
        return match ($mimeType) {
            'text/plain' => 'plaintext',
            'text/html'  => 'html',
            default      => 'x-unknown',
        };
    }

    private function statusToXliff(SegmentStatus $status): string
    {
        return match ($status) {
            SegmentStatus::Untranslated => 'new',
            SegmentStatus::Draft        => 'new',
            SegmentStatus::Translated   => 'translated',
            SegmentStatus::Reviewed     => 'signed-off',
            SegmentStatus::Approved     => 'final',
            SegmentStatus::Rejected     => 'needs-translation',
        };
    }
}
