<?php

declare(strict_types=1);

namespace CatFramework\Terminology\Provider;

use CatFramework\Core\Contract\TerminologyProviderInterface;
use CatFramework\Core\Exception\TerminologyException;
use CatFramework\Core\Model\TermEntry;
use CatFramework\Core\Model\TermMatch;
use CatFramework\Terminology\Parser\TbxParser;
use PDO;

class SqliteTerminologyProvider implements TerminologyProviderInterface
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        $this->pdo = new PDO("sqlite:{$databasePath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->initSchema();
    }

    /**
     * {@inheritdoc}
     *
     * Scan strategy (Decision D12):
     * Load all source terms for the language pair into memory, then iterate
     * with mb_strpos. Case-insensitive for Latin scripts (pre-lowercase both
     * sides). Word-boundary check via space/punctuation detection rather than
     * regex \b, which is unreliable for Arabic/Devanagari.
     */
    public function recognize(
        string $sourceText,
        string $sourceLanguage,
        string $targetLanguage,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT source_term, target_term, source_lang, target_lang,
                    definition, domain, forbidden
             FROM term_entries
             WHERE source_lang = ? AND target_lang = ?'
        );
        $stmt->execute([$sourceLanguage, $targetLanguage]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $isLatin = $this->isLatinScript($sourceLanguage);
        $haystack = $isLatin ? mb_strtolower($sourceText) : $sourceText;

        $matches = [];

        foreach ($rows as $row) {
            $needle = $isLatin ? mb_strtolower($row['source_term']) : $row['source_term'];
            $needleLen = mb_strlen($needle);

            $offset = 0;
            while (($pos = mb_strpos($haystack, $needle, $offset)) !== false) {
                if ($this->isWordBoundary($haystack, $pos, $needleLen)) {
                    $matches[] = new TermMatch(
                        entry: $this->rowToEntry($row),
                        offset: $pos,
                        length: $needleLen,
                    );
                }
                $offset = $pos + 1;
            }
        }

        usort($matches, fn (TermMatch $a, TermMatch $b) => $a->offset <=> $b->offset);

        return $matches;
    }

    /** {@inheritdoc} */
    public function lookup(
        string $term,
        string $sourceLanguage,
        string $targetLanguage,
    ): array {
        $normalized = $this->normalize($term);

        $stmt = $this->pdo->prepare(
            'SELECT source_term, target_term, source_lang, target_lang,
                    definition, domain, forbidden
             FROM term_entries
             WHERE source_term_normalized = ? AND source_lang = ? AND target_lang = ?'
        );
        $stmt->execute([$normalized, $sourceLanguage, $targetLanguage]);

        return array_map(
            fn (array $row) => $this->rowToEntry($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** {@inheritdoc} */
    public function import(string $tbxFilePath): int
    {
        $parser = new TbxParser();

        try {
            $entries = $parser->parseFile($tbxFilePath);
        } catch (\Throwable $e) {
            throw new TerminologyException(
                "TBX import failed: {$e->getMessage()}",
                previous: $e
            );
        }

        $count = 0;
        $this->pdo->beginTransaction();

        try {
            foreach ($entries as $entry) {
                $this->insertEntry($entry);
                $count++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new TerminologyException(
                "TBX import failed during insert: {$e->getMessage()}",
                previous: $e
            );
        }

        return $count;
    }

    /** {@inheritdoc} */
    public function addEntry(TermEntry $entry): void
    {
        $this->insertEntry($entry);
    }

    private function insertEntry(TermEntry $entry): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO term_entries
                (source_term, target_term, source_lang, target_lang,
                 definition, domain, forbidden, source_term_normalized)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $entry->sourceTerm,
            $entry->targetTerm,
            $entry->sourceLanguage,
            $entry->targetLanguage,
            $entry->definition,
            $entry->domain,
            (int) $entry->forbidden,
            $this->normalize($entry->sourceTerm),
        ]);
    }

    private function initSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS term_entries (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                source_term           TEXT    NOT NULL,
                target_term           TEXT    NOT NULL,
                source_lang           TEXT    NOT NULL,
                target_lang           TEXT    NOT NULL,
                definition            TEXT,
                domain                TEXT,
                forbidden             INTEGER NOT NULL DEFAULT 0,
                source_term_normalized TEXT   NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_term_lookup
             ON term_entries (source_lang, target_lang, source_term_normalized)'
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_term_scan
             ON term_entries (source_lang, target_lang)'
        );
    }

    /**
     * Normalize a term for indexed comparison:
     * NFC → lowercase → collapse whitespace → trim.
     */
    private function normalize(string $term): string
    {
        $term = \Normalizer::normalize($term, \Normalizer::FORM_C) ?: $term;
        $term = mb_strtolower($term);
        $term = preg_replace('/\s+/u', ' ', $term) ?? $term;
        return trim($term);
    }

    /**
     * Check that the match at $pos is surrounded by word boundaries.
     *
     * A boundary is: start/end of string, or a character that is not a
     * Unicode letter or digit. We avoid \b in PCRE because it is ASCII-only
     * and mishandles Arabic/Devanagari characters.
     */
    private function isWordBoundary(string $text, int $pos, int $len): bool
    {
        $textLen = mb_strlen($text);
        $end = $pos + $len;

        $beforeOk = $pos === 0 || !$this->isWordChar(mb_substr($text, $pos - 1, 1));
        $afterOk  = $end >= $textLen || !$this->isWordChar(mb_substr($text, $end, 1));

        return $beforeOk && $afterOk;
    }

    /**
     * Returns true if the single character is a Unicode letter or digit
     * (i.e., part of a word — not a boundary).
     */
    private function isWordChar(string $char): bool
    {
        return preg_match('/^\p{L}|\p{N}$/u', $char) === 1;
    }

    /**
     * Heuristic: BCP 47 tags starting with these prefixes use non-Latin
     * scripts that have no case distinction, so we skip lowercasing.
     */
    private function isLatinScript(string $langCode): bool
    {
        $nonLatin = ['ar', 'he', 'fa', 'ur', 'hi', 'bn', 'pa', 'gu', 'ta',
                     'te', 'kn', 'ml', 'si', 'th', 'lo', 'my', 'km', 'ka',
                     'am', 'ti', 'hy', 'zh', 'ja', 'ko'];

        $prefix = strtolower(explode('-', $langCode)[0]);
        return !in_array($prefix, $nonLatin, true);
    }

    private function rowToEntry(array $row): TermEntry
    {
        return new TermEntry(
            sourceTerm: $row['source_term'],
            targetTerm: $row['target_term'],
            sourceLanguage: $row['source_lang'],
            targetLanguage: $row['target_lang'],
            definition: $row['definition'],
            domain: $row['domain'],
            forbidden: (bool) $row['forbidden'],
        );
    }
}
