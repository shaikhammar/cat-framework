<?php

declare(strict_types=1);

namespace CatFramework\Tmx;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\TranslationUnit;

class TmxWriter
{
    /**
     * @param TranslationUnit[] $units
     */
    public function write(array $units, string $tmxPath): void
    {
        $srcLang = $this->deriveSourceLang($units);
        $now     = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<!DOCTYPE tmx SYSTEM "tmx14.dtd">' . "\n";
        $xml .= '<tmx version="1.4">' . "\n";
        $xml .= '  <header' . "\n";
        $xml .= '    creationtool="CatFramework/tmx"' . "\n";
        $xml .= '    creationtoolversion="0.1.0"' . "\n";
        $xml .= '    datatype="PlainText"' . "\n";
        $xml .= '    segtype="sentence"' . "\n";
        $xml .= '    adminlang="en-US"' . "\n";
        $xml .= '    srclang="' . $this->esc($srcLang) . '"' . "\n";
        $xml .= '    o-tmf="TMX"' . "\n";
        $xml .= '    creationdate="' . $now . '"' . "\n";
        $xml .= '  />' . "\n";
        $xml .= '  <body>' . "\n";

        foreach ($units as $unit) {
            $xml .= $this->renderTu($unit);
        }

        $xml .= '  </body>' . "\n";
        $xml .= '</tmx>' . "\n";

        if (file_put_contents($tmxPath, $xml) === false) {
            throw new TmxException("Cannot write TMX to: {$tmxPath}");
        }
    }

    private function renderTu(TranslationUnit $unit): string
    {
        $tuid        = $this->esc($unit->source->id);
        $creationdate = $unit->createdAt
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');

        $tu  = '    <tu tuid="' . $tuid . '" creationdate="' . $creationdate . '"';
        if ($unit->createdBy !== null) {
            $tu .= ' creationid="' . $this->esc($unit->createdBy) . '"';
        }
        $tu .= '>' . "\n";

        // Write metadata as <prop> elements
        foreach ($unit->metadata as $key => $value) {
            if ($key === 'notes') {
                foreach ((array) $value as $note) {
                    $tu .= '      <note>' . $this->esc((string) $note) . '</note>' . "\n";
                }
            } else {
                $tu .= '      <prop type="' . $this->esc($key) . '">' . $this->esc((string) $value) . '</prop>' . "\n";
            }
        }

        if ($unit->lastUsedAt !== null) {
            $lastUsed = $unit->lastUsedAt
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Ymd\THis\Z');
            $tu .= '      <prop type="x-lastUsedAt">' . $lastUsed . '</prop>' . "\n";
        }

        $tu .= $this->renderTuv($unit->source, $unit->sourceLanguage);
        $tu .= $this->renderTuv($unit->target, $unit->targetLanguage);
        $tu .= '    </tu>' . "\n";

        return $tu;
    }

    private function renderTuv(Segment $segment, string $lang): string
    {
        $tuv  = '      <tuv xml:lang="' . $this->esc($lang) . '">' . "\n";
        $tuv .= '        <seg>' . $this->renderSegContent($segment->getElements()) . '</seg>' . "\n";
        $tuv .= '      </tuv>' . "\n";
        return $tuv;
    }

    private function renderSegContent(array $elements): string
    {
        $out     = '';
        $counter = 0;

        foreach ($elements as $el) {
            if (is_string($el)) {
                $out .= $this->esc($el);
            } else {
                $out .= $this->renderInlineCode($el, ++$counter);
            }
        }

        return $out;
    }

    private function renderInlineCode(InlineCode $code, int $i): string
    {
        $data = $this->esc($code->data);

        if ($code->isIsolated) {
            $pos = $code->type === InlineCodeType::OPENING ? 'begin' : 'end';
            return "<it i=\"{$i}\" pos=\"{$pos}\">{$data}</it>";
        }

        return match ($code->type) {
            InlineCodeType::OPENING    => "<bpt i=\"{$i}\">{$data}</bpt>",
            InlineCodeType::CLOSING    => "<ept i=\"{$i}\">{$data}</ept>",
            InlineCodeType::STANDALONE => "<ph i=\"{$i}\">{$data}</ph>",
        };
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @param TranslationUnit[] $units */
    private function deriveSourceLang(array $units): string
    {
        return !empty($units) ? $units[0]->sourceLanguage : '*all*';
    }
}
