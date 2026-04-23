<?php

declare(strict_types=1);

namespace CatFramework\Mt;

use CatFramework\Core\Contract\MachineTranslationInterface;
use CatFramework\Core\Model\Segment;

/**
 * No-op MT adapter that returns empty segments.
 * Use as a safe default when no MT provider is configured,
 * and as a test double when the MT layer must be present but unused.
 */
final class NullMtAdapter implements MachineTranslationInterface
{
    public function translate(Segment $source, string $sourceLanguage, string $targetLanguage): Segment
    {
        return new Segment($source->id, []);
    }

    public function translateBatch(array $sources, string $sourceLanguage, string $targetLanguage): array
    {
        return array_map(
            fn(Segment $s) => new Segment($s->id, []),
            $sources,
        );
    }

    public function getProviderId(): string
    {
        return 'null';
    }
}
