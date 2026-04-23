<?php

declare(strict_types=1);

namespace CatFramework\Mt\DeepL;

use CatFramework\Mt\Exception\MtException;

/**
 * Converts BCP 47 language codes to the codes expected by the DeepL API.
 *
 * DeepL source codes are 2-letter uppercase only (no region variant).
 * DeepL target codes require a region for English, Portuguese, and Chinese.
 * For any code not listed in the TARGET_MAP, the BCP 47 code is uppercased
 * and passed through, allowing the DeepL API to return its own error — this
 * avoids maintaining a hardcoded "supported languages" list that goes stale.
 */
final class DeepLLanguageMapper
{
    private const array UNSUPPORTED = ['ur'];

    /**
     * Codes where DeepL target requires a specific region variant.
     * Keys are lowercase BCP 47 (or base language); values are DeepL codes.
     */
    private const array TARGET_MAP = [
        'en'      => 'EN-GB',
        'en-us'   => 'EN-US',
        'en-gb'   => 'EN-GB',
        'pt'      => 'PT-PT',
        'pt-pt'   => 'PT-PT',
        'pt-br'   => 'PT-BR',
        'zh'      => 'ZH-HANS',
        'zh-hans' => 'ZH-HANS',
        'zh-hant' => 'ZH-HANT',
        'zh-tw'   => 'ZH-HANT',
        'zh-hk'   => 'ZH-HANT',
    ];

    /**
     * Map a BCP 47 source language code to a DeepL source code.
     * DeepL source accepts only the base 2-letter code (no region).
     *
     * @throws MtException If the language is not supported by DeepL.
     */
    public static function toSourceLang(string $bcp47): string
    {
        $base = strtolower(explode('-', $bcp47)[0]);
        self::assertSupported($base);
        return strtoupper($base);
    }

    /**
     * Map a BCP 47 target language code to a DeepL target code.
     * Some targets require a region variant; others pass through uppercased.
     *
     * @throws MtException If the language is not supported by DeepL.
     */
    public static function toTargetLang(string $bcp47): string
    {
        $lower = strtolower($bcp47);
        $base  = explode('-', $lower)[0];
        self::assertSupported($base);
        return self::TARGET_MAP[$lower] ?? strtoupper($bcp47);
    }

    private static function assertSupported(string $baseLang): void
    {
        if (in_array($baseLang, self::UNSUPPORTED, true)) {
            throw new MtException(
                sprintf('DeepL does not support language: %s', $baseLang),
                MtException::LANGUAGE_NOT_SUPPORTED,
            );
        }
    }
}
