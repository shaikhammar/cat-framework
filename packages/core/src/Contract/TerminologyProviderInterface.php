<?php

declare(strict_types=1);

namespace CatFramework\Core\Contract;

use CatFramework\Core\Exception\TerminologyException;
use CatFramework\Core\Model\TermEntry;
use CatFramework\Core\Model\TermMatch;

interface TerminologyProviderInterface
{
    /**
     * Scan running text and find all known terms.
     *
     * Scans the source text for any term in the terminology database for the
     * given language pair. Returns all matches with their positions, so the
     * editor can highlight recognized terms. Results may overlap.
     *
     * @param string $sourceText Plain text to scan (no inline codes).
     * @param string $sourceLanguage BCP 47 code.
     * @param string $targetLanguage BCP 47 code.
     * @return TermMatch[]
     */
    public function recognize(
        string $sourceText,
        string $sourceLanguage,
        string $targetLanguage,
    ): array;

    /**
     * Look up a specific term or phrase.
     *
     * Unlike recognize() which scans text, this searches for a specific query
     * string. Used when a translator selects text and asks "what's the approved
     * translation for this?"
     *
     * @return TermEntry[] All matching entries (may be multiple if the term has
     *     different translations in different domains).
     */
    public function lookup(
        string $term,
        string $sourceLanguage,
        string $targetLanguage,
    ): array;

    /**
     * Import terminology from a TBX file.
     *
     * @return int Number of entries imported.
     * @throws TerminologyException On parse failure.
     */
    public function import(string $tbxFilePath): int;

    /** Add a single term entry. */
    public function addEntry(TermEntry $entry): void;
}
