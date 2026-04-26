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

/**
 * Postgres-backed translation memory using pg_trgm for fast fuzzy pre-filtering.
 *
 * Requires:
 *   - ext-pdo_pgsql
 *   - CREATE EXTENSION IF NOT EXISTS pg_trgm; (run once per database)
 *   - The `tm_units` table created by the cat-framework-api migrations
 *
 * Lookup is two-stage:
 *   Stage 1 (Postgres): pg_trgm similarity() narrows candidates to ~50 rows using a GIN index.
 *   Stage 2 (PHP):      LevenshteinCalculator re-scores candidates at grapheme level and
 *                       filters to the requested minScore.
 *
 * The trgm_threshold is set to minScore * 0.7 so the DB pre-filter is deliberately broad —
 * it would rather return extra candidates than discard a valid match.
 */
class PostgresTranslationMemory implements TranslationMemoryInterface
{
    private LevenshteinCalculator $calculator;

    /** @var NormalizerInterface[] */
    private array $normalizers;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $tmId,
        private readonly float $minSimilarity = 0.5,
        private readonly int $maxResults = 10,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->calculator = new LevenshteinCalculator();
        $this->normalizers = [
            new NfcNormalizer(),
            new LowercaseNormalizer(),
            new WhitespaceNormalizer(),
            new TrimNormalizer(),
        ];
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

        $stmt = $this->pdo->prepare('
            INSERT INTO tm_units
                (tm_id, source_lang, target_lang,
                 source_text, target_text,
                 source_segment, target_segment,
                 source_text_normalized,
                 created_at, last_used_at, created_by, metadata)
            VALUES
                (:tm_id, :source_lang, :target_lang,
                 :source_text, :target_text,
                 :source_segment, :target_segment,
                 :source_text_normalized,
                 :created_at, :last_used_at, :created_by, :metadata::jsonb)
            ON CONFLICT (tm_id, source_lang, target_lang, source_text_normalized)
            DO UPDATE SET
                target_text            = EXCLUDED.target_text,
                target_segment         = EXCLUDED.target_segment,
                last_used_at           = EXCLUDED.last_used_at,
                created_by             = EXCLUDED.created_by,
                metadata               = EXCLUDED.metadata
        ');

        $stmt->execute([
            ':tm_id'                  => $this->tmId,
            ':source_lang'            => $unit->sourceLanguage,
            ':target_lang'            => $unit->targetLanguage,
            ':source_text'            => $sourcePlain,
            ':target_text'            => $unit->target->getPlainText(),
            ':source_segment'         => SegmentSerializer::serialize($unit->source),
            ':target_segment'         => SegmentSerializer::serialize($unit->target),
            ':source_text_normalized' => $normalized,
            ':created_at'             => $unit->createdAt->format(\DateTimeInterface::ATOM),
            ':last_used_at'           => $unit->lastUsedAt?->format(\DateTimeInterface::ATOM),
            ':created_by'             => $unit->createdBy,
            ':metadata'               => json_encode($unit->metadata ?? [], JSON_THROW_ON_ERROR),
        ]);
    }

    public function lookup(
        Segment $source,
        string $sourceLanguage,
        string $targetLanguage,
        float $minScore = 0.7,
        int $maxResults = 5,
    ): array {
        $sourcePlain   = $source->getPlainText();
        $normalized    = $this->normalizeText($sourcePlain);

        if ($this->calculator->graphemeLength($normalized) === 0) {
            return [];
        }

        // Stage 1: pg_trgm pre-filter — broad threshold prevents discarding valid matches.
        // The 0.7 factor means a segment needing 80% Levenshtein similarity gets a 56%
        // trigram threshold, which comfortably includes it even for short segments.
        $trgmThreshold = max(0.1, $minScore * 0.7);

        $stmt = $this->pdo->prepare('
            SELECT *, similarity(source_text_normalized, :query) AS trgm_score
            FROM tm_units
            WHERE tm_id      = :tm_id
              AND source_lang = :source_lang
              AND target_lang = :target_lang
              AND similarity(source_text_normalized, :query2) >= :threshold
            ORDER BY trgm_score DESC
            LIMIT 50
        ');

        $stmt->execute([
            ':tm_id'       => $this->tmId,
            ':source_lang' => $sourceLanguage,
            ':target_lang' => $targetLanguage,
            ':query'       => $normalized,
            ':query2'      => $normalized,
            ':threshold'   => $trgmThreshold,
        ]);

        $candidates = [];

        // Stage 2: PHP Levenshtein re-score for industry-standard CAT percentages.
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
            $this->touchLastUsedAt($row['id']);
            $tu        = $this->rowToTranslationUnit($row);
            $results[] = new MatchResult(
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
        $stmt = $this->pdo->prepare('
            SELECT * FROM tm_units
            WHERE tm_id = :tm_id
            ORDER BY created_at
        ');
        $stmt->execute([':tm_id' => $this->tmId]);

        $units = array_map(fn($row) => $this->rowToTranslationUnit($row), $stmt->fetchAll());

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

    private function touchLastUsedAt(string $id): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $this->pdo->prepare('UPDATE tm_units SET last_used_at = ? WHERE id = ?')
                  ->execute([$now, $id]);
    }

    private function rowToTranslationUnit(array $row): TranslationUnit
    {
        $metadata = $row['metadata'];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        }

        return new TranslationUnit(
            source:         SegmentSerializer::deserialize($row['source_segment']),
            target:         SegmentSerializer::deserialize($row['target_segment']),
            sourceLanguage: $row['source_lang'],
            targetLanguage: $row['target_lang'],
            createdAt:      new \DateTimeImmutable($row['created_at']),
            lastUsedAt:     $row['last_used_at'] !== null
                                ? new \DateTimeImmutable($row['last_used_at'])
                                : null,
            createdBy:      $row['created_by'] ?? null,
            metadata:       $metadata ?? [],
        );
    }
}
