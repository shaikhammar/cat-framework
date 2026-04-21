<?php

declare(strict_types=1);

namespace CatFramework\Core\Model;

class BilingualDocument
{
    /**
     * @param string $sourceLanguage BCP 47 tag, e.g. "en-US", "hi-IN", "ur-PK".
     * @param string $targetLanguage BCP 47 tag.
     * @param string $originalFile Filename of the source document (e.g., "manual.docx").
     *     Not a full path — just the name, for display and re-export.
     * @param string $mimeType MIME type of the original source file.
     *     Used to select the correct filter for file rebuild.
     * @param SegmentPair[] $segmentPairs Ordered segment pairs.
     * @param array<string, mixed> $skeleton Filter-specific data needed to rebuild the file.
     *     Opaque to everything except the filter that created it.
     */
    public function __construct(
        public readonly string $sourceLanguage,
        public readonly string $targetLanguage,
        public readonly string $originalFile,
        public readonly string $mimeType,
        private array $segmentPairs = [],
        public readonly array $skeleton = [],
    ) {}

    /** @return SegmentPair[] */
    public function getSegmentPairs(): array
    {
        return $this->segmentPairs;
    }

    public function addSegmentPair(SegmentPair $pair): void
    {
        $this->segmentPairs[] = $pair;
    }

    /** Lookup by source segment ID. Returns null if not found. */
    public function getSegmentPairById(string $sourceSegmentId): ?SegmentPair
    {
        foreach ($this->segmentPairs as $pair) {
            if ($pair->source->id === $sourceSegmentId) {
                return $pair;
            }
        }

        return null;
    }

    /** Total number of segment pairs. */
    public function count(): int
    {
        return count($this->segmentPairs);
    }
}
