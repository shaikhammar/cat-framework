<?php

declare(strict_types=1);

namespace CatFramework\Project\Store;

use CatFramework\Project\Exception\ProjectException;

/**
 * Stores skeleton bytes as a BLOB in a dedicated `skeletons` table.
 * Works with both SQLite (for .catpack) and PostgreSQL (for the API).
 *
 * The handle returned by store() is the fileId itself, which is the primary key.
 */
class DatabaseSkeletonStore implements SkeletonStoreInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->initSchema();
    }

    public function store(string $fileId, string $format, string $skeletonBytes): string
    {
        $this->pdo->prepare('
            INSERT INTO skeletons (id, format, blob, created_at)
            VALUES (:id, :format, :blob, :created_at)
            ON CONFLICT (id) DO UPDATE SET blob = EXCLUDED.blob, format = EXCLUDED.format
        ')->execute([
            ':id'         => $fileId,
            ':format'     => $format,
            ':blob'       => $skeletonBytes,
            ':created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ]);

        return $fileId;
    }

    public function retrieve(string $handle): string
    {
        $stmt = $this->pdo->prepare('SELECT blob FROM skeletons WHERE id = :id');
        $stmt->execute([':id' => $handle]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new ProjectException("Skeleton not found for handle: {$handle}");
        }

        // PostgreSQL returns BYTEA as a PHP resource stream; SQLite returns string.
        $blob = $row['blob'];

        return is_resource($blob) ? stream_get_contents($blob) : (string) $blob;
    }

    public function delete(string $handle): void
    {
        $this->pdo->prepare('DELETE FROM skeletons WHERE id = :id')
                  ->execute([':id' => $handle]);
    }

    private function initSchema(): void
    {
        // ON CONFLICT syntax is valid for both SQLite and PostgreSQL.
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS skeletons (
                id         TEXT PRIMARY KEY,
                format     TEXT NOT NULL,
                blob       BYTEA NOT NULL,
                created_at TEXT NOT NULL
            )
        ');
    }
}
