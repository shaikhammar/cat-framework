<?php

declare(strict_types=1);

namespace CatFramework\TranslationMemory\Normalizer;

use Normalizer;

class NfcNormalizer implements NormalizerInterface
{
    public function normalize(string $text): string
    {
        return Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
    }
}
