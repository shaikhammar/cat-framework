<?php

declare(strict_types=1);

namespace CatFramework\Core\Contract;

use CatFramework\Core\Exception\TmException;
use CatFramework\Core\Model\MatchResult;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\TranslationUnit;

interface TranslationMemoryInterface
{
    /**
     * Find matching TUs for a source segment.
     *
     * Returns matches sorted by score descending. Exact matches (1.0) first,
     * then fuzzy matches. Scoring algorithm is the engine's concern.
     *
     * @param Segment $source The source segment to match against.
     * @param string $sourceLanguage BCP 47 code.
     * @param string $targetLanguage BCP 47 code.
     * @param float $minScore Minimum score threshold (0.0–1.0). Default 0.7.
     * @param int $maxResults Maximum matches to return. Default 5.
     * @return MatchResult[] Sorted by score descending. Empty if no matches.
     */
    public function lookup(
        Segment $source,
        string $sourceLanguage,
        string $targetLanguage,
        float $minScore = 0.7,
        int $maxResults = 5,
    ): array;

    /**
     * Store a translation unit in the TM.
     *
     * If an exact duplicate (same source text + language pair) exists,
     * updates it with the new target and metadata (most-recent-wins).
     */
    public function store(TranslationUnit $unit): void;

    /**
     * Import TUs from a TMX file.
     *
     * @return int Number of TUs imported (including updates to existing entries).
     * @throws TmException On parse or storage failure.
     */
    public function import(string $tmxFilePath): int;

    /**
     * Export all TUs to a TMX file.
     *
     * @return int Number of TUs exported.
     * @throws TmException On write failure.
     */
    public function export(string $tmxFilePath): int;
}
