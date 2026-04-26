<?php

declare(strict_types=1);

namespace CatFramework\Project\Store;

use CatFramework\Core\Enum\SegmentStatus;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Project\Exception\InvalidStatusTransitionException;

interface SegmentStoreInterface
{
    /**
     * Persist a single SegmentPair as it flows through the WorkflowRunner pipeline.
     * Called once per segment during processing for streaming writes.
     */
    public function persistSegment(SegmentPair $pair, int $segmentNumber, string $fileId): void;

    /**
     * Bulk-persist all SegmentPairs from a BilingualDocument.
     * Returns the fileId used (same as the one passed in).
     */
    public function persist(BilingualDocument $doc, string $fileId): string;

    /**
     * Reconstruct a BilingualDocument from stored segments.
     * Target text is deserialised back into Segment objects via InlineTagSerializer.
     */
    public function hydrate(string $fileId): BilingualDocument;

    /**
     * Update a single segment's target text and/or status.
     *
     * @throws InvalidStatusTransitionException when attempting to move away from Approved.
     */
    public function updateSegment(
        string $segmentId,
        ?string $targetText,
        ?SegmentStatus $status,
    ): void;

    /**
     * List segments for a file, optionally filtered by status.
     *
     * @return StoredSegment[]
     */
    public function getSegments(
        string $fileId,
        ?SegmentStatus $filterStatus = null,
        int $limit = 100,
        int $offset = 0,
    ): array;

    /** Retrieve a single segment by its ID. */
    public function getSegment(string $segmentId): StoredSegment;

    /** Total segment count for a file, optionally filtered by status. */
    public function countSegments(string $fileId, ?SegmentStatus $status = null): int;
}
