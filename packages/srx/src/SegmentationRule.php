<?php

declare(strict_types=1);

namespace CatFramework\Srx;

readonly class SegmentationRule
{
    /**
     * @param bool   $break  True = sentence boundary here; false = prevent break (no-break rule).
     * @param string $before Regex matching text ENDING at the candidate break point.
     * @param string $after  Regex matching text STARTING at the candidate break point.
     */
    public function __construct(
        public bool   $break,
        public string $before,
        public string $after,
    ) {}
}
