<?php

declare(strict_types=1);

namespace CatFramework\TranslationMemory\Normalizer;

class WhitespaceNormalizer implements NormalizerInterface
{
    public function normalize(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', $text);
    }
}
