<?php

declare(strict_types=1);

namespace CatFramework\FilterPo;

use CatFramework\Core\Contract\FileFilterInterface;
use CatFramework\Core\Exception\FilterException;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;

/**
 * GNU gettext PO file filter.
 *
 * Skeleton strategy: each translatable msgstr is replaced by a token
 * "{{SEG:NNN}}" in the stored skeleton. rebuild() substitutes the
 * translated text back, re-encoding any PO special characters.
 *
 * Plural forms: the singular msgid is extracted as the source segment.
 * On rebuild the translation fills msgstr[0]; remaining plural slots
 * (msgstr[1..n]) are set to empty string — they require human post-editing.
 *
 * Skipped entries:
 *   - Empty msgid (the PO header)
 *   - Entries flagged #, fuzzy (kept in skeleton unchanged)
 */
class PoFilter implements FileFilterInterface
{
    public function supports(string $filePath, ?string $mimeType = null): bool
    {
        return strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'po';
    }

    public function getSupportedExtensions(): array
    {
        return ['.po'];
    }

    public function extract(string $filePath, string $sourceLanguage, string $targetLanguage): BilingualDocument
    {
        if (!file_exists($filePath)) {
            throw new FilterException("File not found: {$filePath}");
        }

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new FilterException("Cannot read file: {$filePath}");
        }

        $content = str_replace(["\r\n", "\r"], "\n", $raw);
        $entries = $this->parseEntries($content);

        $pairs  = [];
        $segMap = []; // segId => token
        $seqNo  = 1;

        // Rebuild the skeleton line-by-line, replacing each translatable
        // msgstr block with a token.
        $skeletonLines = explode("\n", $content);

        foreach ($entries as $entry) {
            if ($entry['msgid'] === '' || $entry['fuzzy']) {
                continue; // header or fuzzy: leave skeleton untouched
            }

            $segId = 'seg-' . $seqNo;
            $token = '{{SEG:' . str_pad((string) $seqNo, 3, '0', STR_PAD_LEFT) . '}}';
            $seqNo++;

            $segMap[$segId] = $token;
            $pairs[]        = new SegmentPair(source: new Segment($segId, [$entry['msgid']]));

            // Replace the msgstr block lines with a single token line.
            $replacement = $entry['plural']
                ? ['msgstr[0] "' . $token . '"']
                : ['msgstr "' . $token . '"'];

            // Also blank out remaining plural slots (msgstr[1..n])
            if ($entry['plural']) {
                for ($i = 1; $i < count($entry['msgstr_lines']); $i++) {
                    $replacement[] = 'msgstr[' . $i . '] ""';
                }
            }

            array_splice($skeletonLines, $entry['msgstr_start'], $entry['msgstr_len'], $replacement);

            // Adjust subsequent entry line offsets for the splice delta.
            $delta = count($replacement) - $entry['msgstr_len'];
            foreach ($entries as &$later) {
                if ($later['msgstr_start'] > $entry['msgstr_start']) {
                    $later['msgstr_start'] += $delta;
                }
            }
            unset($later);
        }

        $document = new BilingualDocument(
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            originalFile: basename($filePath),
            mimeType: 'text/x-gettext-translation',
            skeleton: ['lines' => $skeletonLines, 'seg_map' => $segMap],
        );

        foreach ($pairs as $pair) {
            $document->addSegmentPair($pair);
        }

        return $document;
    }

    public function rebuild(BilingualDocument $document, string $outputPath): void
    {
        $lines  = $document->skeleton['lines'];
        $segMap = $document->skeleton['seg_map']; // segId => token

        $pairsBySegId = [];
        foreach ($document->getSegmentPairs() as $pair) {
            $pairsBySegId[$pair->source->id] = $pair;
        }

        foreach ($pairsBySegId as $segId => $pair) {
            $token      = $segMap[$segId];
            $translated = $pair->target?->getPlainText() ?? $pair->source->getPlainText();
            $escaped    = $this->poEscape($translated);

            // Find and replace the token line in-place.
            foreach ($lines as &$line) {
                if (str_contains($line, $token)) {
                    $line = str_replace('"' . $token . '"', '"' . $escaped . '"', $line);
                    break;
                }
            }
            unset($line);
        }

        $result = implode("\n", $lines);

        if (file_put_contents($outputPath, $result) === false) {
            throw new FilterException("Cannot write output file: {$outputPath}");
        }
    }

    // ── Parser ────────────────────────────────────────────────────────────────

    /**
     * Parses a PO file into an array of entry descriptors.
     *
     * Each descriptor contains:
     *   msgid        string  — decoded source text
     *   msgid_plural string  — decoded plural source (empty if not plural)
     *   plural       bool    — true when msgid_plural is present
     *   fuzzy        bool    — true when #, fuzzy flag is set
     *   msgstr_start int     — first line index of the msgstr block (0-based)
     *   msgstr_len   int     — number of lines in the msgstr block
     *   msgstr_lines array   — raw msgstr value lines for plural slot counting
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseEntries(string $content): array
    {
        $lines   = explode("\n", $content);
        $entries = [];
        $i       = 0;
        $total   = count($lines);

        while ($i < $total) {
            // Scan for the start of an entry (msgid keyword)
            $fuzzy  = false;
            $ctxStart = $i;

            // Collect comment block before msgid
            while ($i < $total && (str_starts_with($lines[$i], '#') || $lines[$i] === '')) {
                if (preg_match('/^#,.*\bfuzzy\b/', $lines[$i])) {
                    $fuzzy = true;
                }
                $i++;
            }

            if ($i >= $total) {
                break;
            }

            // Skip msgctxt if present
            if (str_starts_with($lines[$i], 'msgctxt ')) {
                $i++;
                while ($i < $total && str_starts_with($lines[$i], '"')) {
                    $i++;
                }
            }

            if (!str_starts_with($lines[$i], 'msgid ')) {
                $i++;
                continue;
            }

            // Parse msgid
            [$msgid, $i] = $this->readValue($lines, $i, $total);

            // Parse optional msgid_plural
            $msgidPlural = '';
            $plural      = false;
            if ($i < $total && str_starts_with($lines[$i], 'msgid_plural ')) {
                [$msgidPlural, $i] = $this->readValue($lines, $i, $total);
                $plural            = true;
            }

            // Parse msgstr block
            $msgstrStart = $i;
            $msgstrLines = [];
            if ($plural) {
                while ($i < $total && preg_match('/^msgstr\[\d+\]/', $lines[$i])) {
                    $msgstrLines[] = $lines[$i];
                    $i++;
                    while ($i < $total && str_starts_with($lines[$i], '"')) {
                        $msgstrLines[] = $lines[$i];
                        $i++;
                    }
                }
            } else {
                if ($i < $total && str_starts_with($lines[$i], 'msgstr ')) {
                    $msgstrLines[] = $lines[$i];
                    $i++;
                    while ($i < $total && str_starts_with($lines[$i], '"')) {
                        $msgstrLines[] = $lines[$i];
                        $i++;
                    }
                }
            }

            if ($msgstrLines === []) {
                continue;
            }

            $entries[] = [
                'msgid'        => $msgid,
                'msgid_plural' => $msgidPlural,
                'plural'       => $plural,
                'fuzzy'        => $fuzzy,
                'msgstr_start' => $msgstrStart,
                'msgstr_len'   => count($msgstrLines),
                'msgstr_lines' => $msgstrLines,
            ];
        }

        return $entries;
    }

    /**
     * Reads a PO keyword + value, potentially spanning multiple quoted lines.
     * Returns [decodedString, nextLineIndex].
     *
     * @return array{string, int}
     */
    private function readValue(array $lines, int $i, int $total): array
    {
        $line  = $lines[$i];
        $space = strpos($line, ' ');
        $value = $space !== false ? substr($line, $space + 1) : '';
        $i++;

        $collected = $this->unquoteLine($value);

        // Continuation lines start with a bare "..."
        while ($i < $total && str_starts_with($lines[$i], '"')) {
            $collected .= $this->unquoteLine($lines[$i]);
            $i++;
        }

        return [$collected, $i];
    }

    /** Strip surrounding quotes and decode PO escape sequences. */
    private function unquoteLine(string $line): string
    {
        $line = trim($line);
        if (strlen($line) < 2 || $line[0] !== '"' || $line[-1] !== '"') {
            return '';
        }

        return strtr(
            substr($line, 1, -1),
            ['\\n' => "\n", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\'],
        );
    }

    /** Encode a plain string for embedding in a PO msgstr value. */
    private function poEscape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\t" => '\\t']);
    }
}
