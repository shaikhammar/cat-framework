<?php

declare(strict_types=1);

namespace CatFramework\TranslationMemory;

use CatFramework\Core\Contract\TranslationMemoryInterface;
use CatFramework\Core\Enum\MatchType;
use CatFramework\Core\Exception\TmException;
use CatFramework\Core\Model\MatchResult;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\TranslationUnit;
use CatFramework\Tmx\TmxReader;
use CatFramework\Tmx\TmxWriter;
use CatFramework\TranslationMemory\Normalizer\LowercaseNormalizer;
use CatFramework\TranslationMemory\Normalizer\NfcNormalizer;
use CatFramework\TranslationMemory\Normalizer\NormalizerInterface;
use CatFramework\TranslationMemory\Normalizer\TrimNormalizer;
use CatFramework\TranslationMemory\Normalizer\WhitespaceNormalizer;

class SqliteTranslationMemory implements TranslationMemoryInterface
{
    private LevenshteinCalculator $calculator;

    /** @var NormalizerInterface[] */
    private array $normalizers;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->calculator = new LevenshteinCalculator();
        $this->normalizers = [
            new NfcNormalizer(),
            new LowercaseNormalizer(),
            new WhitespaceNormalizer(),
            new TrimNormalizer(),
        ];

        $this->initializeSchema();
    }

    /** @param NormalizerInterface[] $normalizers */
    public function setNormalizers(array $normalizers): void
    {
        $this->normalizers = $normalizers;
    }

    public function store(TranslationUnit $unit): void
    {
        $sourcePlain = $unit->source->getPlainText();
        $normalized  = $this->normalizeText($sourcePlain);
        $charCount   = $this->calculator->graphemeLength($normalized);

        $stmt = $this->pdo->prepare('
            INSERT INTO translation_units
                (source_text, target_text, source_segment, target_segment,
                 source_lang, target_lang, char_count, source_text_normalized,
                 created_at, last_used_at, created_by, metadata)
            VALUES
                (:source_text, :target_text, :source_segment, :target_segment,
                 :source_lang, :target_lang, :char_count, :source_text_normalized,
                 :created_at, :last_used_at, :created_by, :metadata)
            ON CONFLICT(source_lang, target_lang, source_text_normalized)
            DO UPDATE SET
                target_text     = excluded.target_text,
                target_segment  = excluded.target_segment,
                created_at      = excluded.created_at,
                last_used_at    = excluded.last_used_at,
                created_by      = excluded.created_by,
                metadata        = excluded.metadata
        ');

        $stmt->execute([
            ':source_text'            => $sourcePlain,
            ':target_text'            => $unit->target->getPlainText(),
            ':source_segment'         => SegmentSerializer::serialize($unit->source),
            ':target_segment'         => SegmentSerializer::serialize($unit->target),
            ':source_lang'            => $unit->sourceLanguage,
            ':target_lang'            => $unit->targetLanguage,
            ':char_count'             => $charCount,
            ':source_text_normalized' => $normalized,
            ':created_at'             => $unit->createdAt->format(\DateTimeInterface::ATOM),
            ':last_used_at'           => $unit->lastUsedAt?->format(\DateTimeInterface::ATOM),
            ':created_by'             => $unit->createdBy,
            ':metadata'               => json_encode($unit->metadata, JSON_THROW_ON_ERROR),
        ]);
    }

    public function lookup(
        Segment $source,
        string $sourceLanguage,
        string $targetLanguage,
        float $minScore = 0.7,
        int $maxResults = 5,
    ): array {
        $sourcePlain = $source->getPlainText();
        $normalized  = $this->normalizeText($sourcePlain);
        $sourceLen   = $this->calculator->graphemeLength($normalized);

        if ($sourceLen === 0) {
            return [];
        }

        // Length pre-filter: skip candidates that can't possibly reach $minScore
        $minLen = (int) ceil($sourceLen * $minScore);
        $maxLen = (int) floor($sourceLen / $minScore);

        $stmt = $this->pdo->prepare('
            SELECT * FROM translation_units
            WHERE source_lang = :source_lang
              AND target_lang = :target_lang
              AND char_count BETWEEN :min_len AND :max_len
        ');
        $stmt->execute([
            ':source_lang' => $sourceLanguage,
            ':target_lang' => $targetLanguage,
            ':min_len'     => $minLen,
            ':max_len'     => $maxLen,
        ]);

        $candidates = [];

        foreach ($stmt->fetchAll() as $row) {
            $score = $this->calculator->similarity($normalized, $row['source_text_normalized']);

            if ($score < $minScore) {
                continue;
            }

            $candidates[] = ['score' => $score, 'row' => $row];
        }

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $candidates = array_slice($candidates, 0, $maxResults);

        $results = [];
        foreach ($candidates as ['score' => $score, 'row' => $row]) {
            $this->touchLastUsedAt((int) $row['id']);
            $tu          = $this->rowToTranslationUnit($row);
            $results[]   = new MatchResult(
                translationUnit: $tu,
                score:           $score,
                type:            $this->classifyMatch($score, $source, $tu->source),
            );
        }

        return $results;
    }

    public function import(string $tmxFilePath): int
    {
        $count = 0;
        try {
            foreach ((new TmxReader())->stream($tmxFilePath) as $unit) {
                $this->store($unit);
                $count++;
            }
        } catch (\Throwable $e) {
            throw new TmException("TMX import failed: " . $e->getMessage(), 0, $e);
        }

        return $count;
    }

    public function export(string $tmxFilePath): int
    {
        $rows  = $this->pdo->query('SELECT * FROM translation_units ORDER BY id')->fetchAll();
        $units = array_map(fn($row) => $this->rowToTranslationUnit($row), $rows);

        try {
            (new TmxWriter())->write($units, $tmxFilePath);
        } catch (\Throwable $e) {
            throw new TmException("TMX export failed: " . $e->getMessage(), 0, $e);
        }

        return count($units);
    }

    // --- private helpers ---

    private function normalizeText(string $text): string
    {
        foreach ($this->normalizers as $normalizer) {
            $text = $normalizer->normalize($text);
        }

        return $text;
    }

    private function classifyMatch(float $score, Segment $query, Segment $stored): MatchType
    {
        if ($score < 1.0) {
            return MatchType::FUZZY;
        }

        $queryCodes  = $query->getInlineCodes();
        $storedCodes = $stored->getInlineCodes();

        if (count($queryCodes) !== count($storedCodes)) {
            return MatchType::EXACT_TEXT;
        }

        foreach ($queryCodes as $i => $code) {
            if ($code->type !== $storedCodes[$i]->type || $code->id !== $storedCodes[$i]->id) {
                return MatchType::EXACT_TEXT;
            }
        }

        return MatchType::EXACT;
    }

    private function touchLastUsedAt(int $id): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $this->pdo->prepare('UPDATE translation_units SET last_used_at = ? WHERE id = ?')
                  ->execute([$now, $id]);
    }

    private function rowToTranslationUnit(array $row): TranslationUnit
    {
        return new TranslationUnit(
            source:         SegmentSerializer::deserialize($row['source_segment']),
            target:         SegmentSerializer::deserialize($row['target_segment']),
            sourceLanguage: $row['source_lang'],
            targetLanguage: $row['target_lang'],
            createdAt:      new \DateTimeImmutable($row['created_at']),
            lastUsedAt:     $row['last_used_at'] !== null
                                ? new \DateTimeImmutable($row['last_used_at'])
                                : null,
            createdBy:      $row['created_by'],
            metadata:       json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    private function initializeSchema(): void
    {
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS translation_units (
                id                      INTEGER PRIMARY KEY AUTOINCREMENT,
                source_text             TEXT    NOT NULL,
                target_text             TEXT    NOT NULL,
                source_segment          TEXT    NOT NULL,
                target_segment          TEXT    NOT NULL,
                source_lang             TEXT    NOT NULL,
                target_lang             TEXT    NOT NULL,
                char_count              INTEGER NOT NULL,
                source_text_normalized  TEXT    NOT NULL,
                created_at              TEXT    NOT NULL,
                last_used_at            TEXT,
                created_by              TEXT,
                metadata                TEXT    NOT NULL DEFAULT \'{}\'
            )
        ');
        $this->pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_tm_filter
            ON translation_units(source_lang, target_lang, char_count)
        ');
        $this->pdo->exec('
            CREATE UNIQUE INDEX IF NOT EXISTS idx_tm_dedup
            ON translation_units(source_lang, target_lang, source_text_normalized)
        ');
    }
}
