<?php

declare(strict_types=1);

namespace CatFramework\TranslationMemory\Normalizer;

class TrimNormalizer implements NormalizerInterface
{
    public function normalize(string $text): string
    {
        return trim($text);
    }
}
