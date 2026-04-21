<?php

declare(strict_types=1);

namespace CatFramework\FilterPlaintext;

use CatFramework\Core\Contract\FileFilterInterface;
use CatFramework\Core\Exception\FilterException;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;

class PlainTextFilter implements FileFilterInterface
{
    public function supports(string $filePath, ?string $mimeType = null): bool
    {
        return strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'txt';
    }

    public function getSupportedExtensions(): array
    {
        return ['.txt'];
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

        $content = $this->toUtf8($raw);
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // Split on 2+ newlines, keeping the separators in the result.
        // Odd-indexed slots are separators, even-indexed slots are text chunks.
        // Example: "A\n\nB\n\n\nC" → ['A', '\n\n', 'B', '\n\n\n', 'C']
        $parts = preg_split('/(\n{2,})/', $content, flags: PREG_SPLIT_DELIM_CAPTURE);

        // Map part index → segment ID for non-empty even-indexed slots.
        // Empty/whitespace-only slots are non-translatable and pass through as-is.
        $segMap = [];
        $pairs  = [];
        $seqNo  = 1;

        foreach ($parts as $i => $part) {
            if ($i % 2 !== 0) {
                continue; // separator slot
            }
            if (trim($part) === '') {
                continue; // whitespace-only: keep in parts array, skip as segment
            }

            $segId        = 'seg-' . $seqNo++;
            $segMap[$i]   = $segId;
            $pairs[]      = new SegmentPair(source: new Segment($segId, [$part]));
        }

        $document = new BilingualDocument(
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            originalFile: basename($filePath),
            mimeType: 'text/plain',
            skeleton: ['parts' => $parts, 'seg_map' => $segMap],
        );

        foreach ($pairs as $pair) {
            $document->addSegmentPair($pair);
        }

        return $document;
    }

    public function rebuild(BilingualDocument $document, string $outputPath): void
    {
        $parts  = $document->skeleton['parts'];
        $segMap = $document->skeleton['seg_map']; // [partIndex => segId]

        $pairsBySegId = [];
        foreach ($document->getSegmentPairs() as $pair) {
            $pairsBySegId[$pair->source->id] = $pair;
        }

        $result = '';
        foreach ($parts as $i => $part) {
            if (!isset($segMap[$i])) {
                $result .= $part; // separator or non-translatable whitespace chunk
                continue;
            }

            $pair   = $pairsBySegId[$segMap[$i]];
            $target = $pair->target?->getPlainText();
            $result .= ($target !== null && $target !== '') ? $target : $pair->source->getPlainText();
        }

        if (file_put_contents($outputPath, $result) === false) {
            throw new FilterException("Cannot write output file: {$outputPath}");
        }
    }

    private function toUtf8(string $content): string
    {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], strict: true);
        if ($encoding === false || $encoding === 'UTF-8') {
            return $content;
        }
        return mb_convert_encoding($content, 'UTF-8', $encoding);
    }
}
