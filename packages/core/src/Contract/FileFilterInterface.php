<?php

declare(strict_types=1);

namespace CatFramework\Core\Contract;

use CatFramework\Core\Exception\FilterException;
use CatFramework\Core\Model\BilingualDocument;

interface FileFilterInterface
{
    /**
     * Can this filter handle the given file?
     *
     * Checks file extension and optionally MIME type. Does NOT open or
     * parse the file (that happens in extract). This is a cheap gate
     * for filter selection.
     *
     * @param string $filePath Path to the source file.
     * @param string|null $mimeType Optional MIME type hint (from upload, HTTP header, etc.).
     */
    public function supports(string $filePath, ?string $mimeType = null): bool;

    /**
     * Parse the source file into a BilingualDocument.
     *
     * Extracts all translatable text as SegmentPairs (target = null).
     * Stores a skeleton in the BilingualDocument so rebuild() can
     * reconstruct the original file structure.
     *
     * Does NOT perform segmentation. Each SegmentPair.source is one
     * structural unit from the file (a paragraph, text node, etc.).
     * Sentence-level segmentation is the SegmentationEngine's job.
     *
     * @throws FilterException On parse failure.
     */
    public function extract(
        string $filePath,
        string $sourceLanguage,
        string $targetLanguage,
    ): BilingualDocument;

    /**
     * Rebuild the source file with translations from the BilingualDocument.
     *
     * Uses the skeleton stored during extract() plus the target Segments
     * to produce a translated version of the original file. Non-translatable
     * content (images, styles, metadata) is preserved exactly.
     * Untranslated segments fall back to source text.
     *
     * @param BilingualDocument $document Must have been produced by this filter.
     * @param string $outputPath Where to write the translated file.
     * @throws FilterException On rebuild failure.
     */
    public function rebuild(BilingualDocument $document, string $outputPath): void;

    /**
     * File extensions this filter handles, lowercase with leading dot.
     * e.g., ['.docx'], ['.html', '.htm'], ['.txt'].
     *
     * @return string[]
     */
    public function getSupportedExtensions(): array;
}
