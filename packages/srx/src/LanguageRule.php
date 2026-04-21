<?php

declare(strict_types=1);

namespace CatFramework\Srx;

readonly class LanguageRule
{
    /**
     * @param string             $name  The languagerulename from the SRX file.
     * @param SegmentationRule[] $rules Ordered rules; first match wins (cascade model).
     */
    public function __construct(
        public string $name,
        public array  $rules,
    ) {}
}
