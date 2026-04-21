<?php

declare(strict_types=1);

namespace CatFramework\TranslationMemory\Normalizer;

class LowercaseNormalizer implements NormalizerInterface
{
    public function normalize(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }
}
