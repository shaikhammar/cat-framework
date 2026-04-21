<?php

declare(strict_types=1);

namespace CatFramework\TranslationMemory\Normalizer;

interface NormalizerInterface
{
    public function normalize(string $text): string;
}
