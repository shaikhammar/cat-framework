<?php

declare(strict_types=1);

namespace CatFramework\Core\Contract;

use CatFramework\Core\Exception\MtException;
use CatFramework\Core\Model\Segment;

interface MachineTranslationInterface
{
    /**
     * Translate a single segment.
     *
     * The adapter handles InlineCodes: convert to a format the MT API
     * understands (XML tags, placeholders, or strip them), then reconstruct
     * codes in the output Segment. If the API doesn't support tags, strip
     * codes before sending and return a Segment with no codes.
     *
     * @param Segment $source Source segment (may contain InlineCodes).
     * @param string $sourceLanguage BCP 47 code.
     * @param string $targetLanguage BCP 47 code.
     * @return Segment The machine-translated target segment.
     * @throws MtException On API failure.
     */
    public function translate(
        Segment $source,
        string $sourceLanguage,
        string $targetLanguage,
    ): Segment;

    /**
     * Translate multiple segments in one API call.
     *
     * Reduces HTTP round trips. Implementations that don't support batching
     * can loop over translate() internally.
     *
     * @param Segment[] $sources
     * @return Segment[] Same order as input.
     * @throws MtException On API failure.
     */
    public function translateBatch(
        array $sources,
        string $sourceLanguage,
        string $targetLanguage,
    ): array;

    /**
     * Identifier for this MT provider. Used in MatchResult metadata so
     * the translator knows where a suggestion came from.
     * e.g., "deepl", "google_v3".
     */
    public function getProviderId(): string;
}
