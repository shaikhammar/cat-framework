<?php

declare(strict_types=1);

namespace CatFramework\Project\Store;

use CatFramework\Core\Enum\SegmentStatus;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Core\Serializer\InlineTagSerializer;
use CatFramework\Project\Exception\InvalidStatusTransitionException;

/**
 * SQLite-backed segment store. Used for .catpack archives and offline workflows.
 * Initialises its own schema on first use — no external migration required.
 */
class SqliteSegmentStore implements SegmentStoreInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->initSchema();
    }

    public function persistSegment(SegmentPair $pair, int $segmentNumber, string $fileId): void
    {
        $src  = InlineTagSerializer::serialize($pair->source);
        $now  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $id   = $fileId . '_' . $segmentNumber;

        $tgtText = null;
        $tgtTags = '[]';
        if ($pair->target !== null) {
            $tgt     = InlineTagSerializer::serialize($pair->target);
            $tgtText = $tgt->text;
            $tgtTags = json_encode($tgt->tagMap, JSON_THROW_ON_ERROR);
        }

        $this->pdo->prepare('
            INSERT OR REPLACE INTO segments
                (id, file_id, segment_number, source_text, target_text,
                 source_tags, target_tags, status, word_count, created_at, updated_at)
            VALUES
                (:id, :file_id, :seg_num, :src_text, :tgt_text,
                 :src_tags, :tgt_tags, :status, :word_count, :created_at, :updated_at)
        ')->execute([
            ':id'         => $id,
            ':file_id'    => $fileId,
            ':seg_num'    => $segmentNumber,
            ':src_text'   => $src->text,
            ':tgt_text'   => $tgtText,
            ':src_tags'   => json_encode($src->tagMap, JSON_THROW_ON_ERROR),
            ':tgt_tags'   => $tgtTags,
            ':status'     => $pair->status->value,
            ':word_count' => $this->countWords($pair->source->getPlainText()),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function persist(BilingualDocument $doc, string $fileId): string
    {
        $this->pdo->beginTransaction();
        try {
            foreach ($doc->getSegmentPairs() as $i => $pair) {
                $this->persistSegment($pair, $i + 1, $fileId);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $fileId;
    }

    public function hydrate(string $fileId): BilingualDocument
    {
        $rows  = $this->pdo->prepare('SELECT * FROM segments WHERE file_id = :fid ORDER BY segment_number');
        $rows->execute([':fid' => $fileId]);

        $pairs = [];
        foreach ($rows->fetchAll() as $row) {
            $srcTags = json_decode($row['source_tags'], true, 512, JSON_THROW_ON_ERROR);
            $tgtTags = json_decode($row['target_tags'], true, 512, JSON_THROW_ON_ERROR);

            $source = InlineTagSerializer::deserialize($row['source_text'], $srcTags, $row['id'] . '_src');
            $target = $row['target_text'] !== null
                ? InlineTagSerializer::deserialize($row['target_text'], $tgtTags, $row['id'] . '_tgt')
                : null;

            $pairs[] = new SegmentPair(
                source: $source,
                target: $target,
                status: SegmentStatus::from($row['status']),
            );
        }

        return new BilingualDocument('', '', '', '', $pairs);
    }

    public function updateSegment(string $segmentId, ?string $targetText, ?SegmentStatus $status): void
    {
        $row = $this->pdo->prepare('SELECT status FROM segments WHERE id = :id');
        $row->execute([':id' => $segmentId]);
        $current = $row->fetch();

        if ($current === false) {
            throw new \RuntimeException("Segment '{$segmentId}' not found.");
        }

        $currentStatus = SegmentStatus::from($current['status']);

        if ($currentStatus === SegmentStatus::Approved && $status !== null && $status !== SegmentStatus::Approved) {
            throw new InvalidStatusTransitionException($currentStatus, $status);
        }

        $now    = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $newVal = $status?->value ?? $current['status'];

        $this->pdo->prepare('
            UPDATE segments
            SET target_text = COALESCE(:tgt, target_text),
                status      = :status,
                updated_at  = :now
            WHERE id = :id
        ')->execute([
            ':tgt'    => $targetText,
            ':status' => $newVal,
            ':now'    => $now,
            ':id'     => $segmentId,
        ]);
    }

    public function getSegments(
        string $fileId,
        ?SegmentStatus $filterStatus = null,
        int $limit = 100,
        int $offset = 0,
    ): array {
        if ($filterStatus !== null) {
            $stmt = $this->pdo->prepare('
                SELECT * FROM segments
                WHERE file_id = :fid AND status = :status
                ORDER BY segment_number LIMIT :lim OFFSET :off
            ');
            $stmt->execute([':fid' => $fileId, ':status' => $filterStatus->value, ':lim' => $limit, ':off' => $offset]);
        } else {
            $stmt = $this->pdo->prepare('
                SELECT * FROM segments WHERE file_id = :fid
                ORDER BY segment_number LIMIT :lim OFFSET :off
            ');
            $stmt->execute([':fid' => $fileId, ':lim' => $limit, ':off' => $offset]);
        }

        return array_map([$this, 'rowToStored'], $stmt->fetchAll());
    }

    public function getSegment(string $segmentId): StoredSegment
    {
        $stmt = $this->pdo->prepare('SELECT * FROM segments WHERE id = :id');
        $stmt->execute([':id' => $segmentId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new \RuntimeException("Segment '{$segmentId}' not found.");
        }

        return $this->rowToStored($row);
    }

    public function countSegments(string $fileId, ?SegmentStatus $status = null): int
    {
        if ($status !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM segments WHERE file_id = :fid AND status = :status');
            $stmt->execute([':fid' => $fileId, ':status' => $status->value]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM segments WHERE file_id = :fid');
            $stmt->execute([':fid' => $fileId]);
        }

        return (int) $stmt->fetchColumn();
    }

    private function rowToStored(array $row): StoredSegment
    {
        return new StoredSegment(
            id:             $row['id'],
            fileId:         $row['file_id'],
            segmentNumber:  (int) $row['segment_number'],
            sourceText:     $row['source_text'],
            targetText:     $row['target_text'],
            sourceTags:     json_decode($row['source_tags'], true, 512, JSON_THROW_ON_ERROR),
            targetTags:     json_decode($row['target_tags'], true, 512, JSON_THROW_ON_ERROR),
            status:         SegmentStatus::from($row['status']),
            wordCount:      (int) $row['word_count'],
            tmMatchPercent: isset($row['tm_match_percent']) ? (int) $row['tm_match_percent'] : null,
            tmMatchOrigin:  $row['tm_match_origin'] ?? null,
            contextBefore:  $row['context_before'] ?? null,
            contextAfter:   $row['context_after'] ?? null,
            note:           $row['note'] ?? null,
            createdAt:      new \DateTimeImmutable($row['created_at']),
            updatedAt:      new \DateTimeImmutable($row['updated_at']),
        );
    }

    private function countWords(string $text): int
    {
        $text = trim($text);

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text));
    }

    private function initSchema(): void
    {
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS segments (
                id               TEXT PRIMARY KEY,
                file_id          TEXT NOT NULL,
                segment_number   INTEGER NOT NULL,
                source_text      TEXT NOT NULL,
                target_text      TEXT,
                source_tags      TEXT NOT NULL DEFAULT \'[]\',
                target_tags      TEXT NOT NULL DEFAULT \'[]\',
                status           TEXT NOT NULL DEFAULT \'untranslated\',
                word_count       INTEGER NOT NULL DEFAULT 0,
                tm_match_percent INTEGER,
                tm_match_origin  TEXT,
                context_before   TEXT,
                context_after    TEXT,
                note             TEXT,
                created_at       TEXT NOT NULL,
                updated_at       TEXT NOT NULL
            )
        ');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_seg_file ON segments(file_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_seg_file_status ON segments(file_id, status)');
    }
}
