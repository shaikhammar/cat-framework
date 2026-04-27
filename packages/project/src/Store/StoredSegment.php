<?php

declare(strict_types=1);

namespace CatFramework\Project\Store;

use CatFramework\Core\Enum\SegmentStatus;

/**
 * Read model returned by SegmentStoreInterface. Carries serialized text
 * (with {N}/{/N}/{N/} placeholders) alongside the tag map needed to
 * reconstruct the original InlineCode objects on export.
 */
readonly class StoredSegment
{
    public function __construct(
        public string $id,
        public string $fileId,
        public int $segmentNumber,
        /** Plain text with {N}, {/N}, {N/} tag placeholders. */
        public string $sourceText,
        public ?string $targetText,
        /** @var array<array{id:int,type:string,data:string,displayText:string}> */
        public array $sourceTags,
        /** @var array<array{id:int,type:string,data:string,displayText:string}> */
        public array $targetTags,
        public SegmentStatus $status,
        public int $wordCount,
        public ?int $tmMatchPercent,
        public ?string $tmMatchOrigin,
        public ?string $contextBefore,
        public ?string $contextAfter,
        public ?string $note,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        /** Filter-assigned segment ID (e.g. "seg-1") — required for skeleton-based rebuild. */
        public string $sourceSegmentId = '',
    ) {}
}
