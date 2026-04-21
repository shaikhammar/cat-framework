<?php

declare(strict_types=1);

namespace CatFramework\Srx;

class SegmentationRuleSet
{
    /**
     * @param array<string, LanguageRule>                $languageRules  name => LanguageRule
     * @param array<int, array{pattern:string,rule:string}> $mapRules  Ordered pattern→name pairs.
     */
    public function __construct(
        private readonly array $languageRules,
        private readonly array $mapRules,
    ) {}

    /**
     * Returns the LanguageRule for the given BCP 47 code.
     *
     * Matches patterns case-insensitively from the start of the code
     * (e.g. pattern "EN.*" matches "en-US"). First matching map rule wins.
     * Returns an empty LanguageRule (no segmentation) if nothing matches.
     */
    public function rulesFor(string $languageCode): LanguageRule
    {
        foreach ($this->mapRules as $mapRule) {
            if (preg_match('/^(?:' . $mapRule['pattern'] . ')/i', $languageCode)) {
                $name = $mapRule['rule'];
                return $this->languageRules[$name] ?? new LanguageRule($name, []);
            }
        }

        return new LanguageRule('empty', []);
    }

    /** @return array<string, LanguageRule> */
    public function getLanguageRules(): array
    {
        return $this->languageRules;
    }
}
