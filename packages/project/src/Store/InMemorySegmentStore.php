<?php

declare(strict_types=1);

namespace CatFramework\Project\Store;

use CatFramework\Core\Enum\SegmentStatus;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Core\Serializer\InlineTagSerializer;
use CatFramework\Project\Exception\InvalidStatusTransitionException;

/**
 * Non-persistent adapter. Default for CLI tools and tests — no setup required.
 * Segments live in-process memory and are lost when the process exits.
 */
class InMemorySegmentStore implements SegmentStoreInterface
{
    /** @var array<string, StoredSegment[]> fileId → segments */
    private array $files = [];

    /** @var array<string, StoredSegment> segmentId → segment */
    private array $index = [];

    public function persistSegment(SegmentPair $pair, int $segmentNumber, string $fileId): void
    {
        $segment = $this->pairToStored($pair, $segmentNumber, $fileId);
        $this->files[$fileId][]        = $segment;
        $this->index[$segment->id]     = $segment;
    }

    public function persist(BilingualDocument $doc, string $fileId): string
    {
        foreach ($doc->getSegmentPairs() as $i => $pair) {
            $this->persistSegment($pair, $i + 1, $fileId);
        }

        return $fileId;
    }

    public function hydrate(string $fileId): BilingualDocument
    {
        $segments = $this->files[$fileId] ?? [];
        $pairs    = [];

        foreach ($segments as $stored) {
            $sourceId = $stored->sourceSegmentId !== '' ? $stored->sourceSegmentId : ($stored->id . '_src');
            $source   = InlineTagSerializer::deserialize($stored->sourceText, $stored->sourceTags, $sourceId);
            $target   = $stored->targetText !== null
                ? InlineTagSerializer::deserialize($stored->targetText, $stored->targetTags, $stored->id . '_tgt')
                : null;

            $pairs[] = new SegmentPair(
                source: $source,
                target: $target,
                status: $stored->status,
            );
        }

        return new BilingualDocument('', '', '', '', $pairs);
    }

    public function updateSegment(string $segmentId, ?string $targetText, ?SegmentStatus $status): void
    {
        $current = $this->index[$segmentId] ?? null;

        if ($current === null) {
            throw new \RuntimeException("Segment '{$segmentId}' not found.");
        }

        if ($current->status === SegmentStatus::Approved && $status !== null && $status !== SegmentStatus::Approved) {
            throw new InvalidStatusTransitionException($current->status, $status);
        }

        $now     = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $updated = new StoredSegment(
            id:             $current->id,
            fileId:         $current->fileId,
            segmentNumber:  $current->segmentNumber,
            sourceText:     $current->sourceText,
            targetText:     $targetText ?? $current->targetText,
            sourceTags:     $current->sourceTags,
            targetTags:     $current->targetTags,
            status:         $status ?? $current->status,
            wordCount:      $current->wordCount,
            tmMatchPercent: $current->tmMatchPercent,
            tmMatchOrigin:  $current->tmMatchOrigin,
            contextBefore:  $current->contextBefore,
            contextAfter:   $current->contextAfter,
            note:           $current->note,
            createdAt:      $current->createdAt,
            updatedAt:      $now,
        );

        $this->index[$segmentId] = $updated;

        // Update the file array entry
        foreach ($this->files[$current->fileId] as $i => $seg) {
            if ($seg->id === $segmentId) {
                $this->files[$current->fileId][$i] = $updated;
                break;
            }
        }
    }

    public function getSegments(
        string $fileId,
        ?SegmentStatus $filterStatus = null,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $all = $this->files[$fileId] ?? [];

        if ($filterStatus !== null) {
            $all = array_values(array_filter($all, fn($s) => $s->status === $filterStatus));
        }

        return array_slice($all, $offset, $limit);
    }

    public function getSegment(string $segmentId): StoredSegment
    {
        return $this->index[$segmentId]
            ?? throw new \RuntimeException("Segment '{$segmentId}' not found.");
    }

    public function countSegments(string $fileId, ?SegmentStatus $status = null): int
    {
        $all = $this->files[$fileId] ?? [];

        if ($status !== null) {
            $all = array_filter($all, fn($s) => $s->status === $status);
        }

        return count($all);
    }

    private function pairToStored(SegmentPair $pair, int $segmentNumber, string $fileId): StoredSegment
    {
        $serialized = InlineTagSerializer::serialize($pair->source);
        $now        = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $targetText = null;
        $targetTags = [];
        if ($pair->target !== null) {
            $tgt        = InlineTagSerializer::serialize($pair->target);
            $targetText = $tgt->text;
            $targetTags = $tgt->tagMap;
        }

        return new StoredSegment(
            id:               $fileId . '_' . $segmentNumber,
            fileId:           $fileId,
            segmentNumber:    $segmentNumber,
            sourceText:       $serialized->text,
            targetText:       $targetText,
            sourceTags:       $serialized->tagMap,
            targetTags:       $targetTags,
            status:           $pair->status,
            wordCount:        $this->countWords($pair->source->getPlainText()),
            tmMatchPercent:   null,
            tmMatchOrigin:    null,
            contextBefore:    null,
            contextAfter:     null,
            note:             null,
            createdAt:        $now,
            updatedAt:        $now,
            sourceSegmentId:  $pair->source->id,
        );
    }

    private function countWords(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text));
    }
}
