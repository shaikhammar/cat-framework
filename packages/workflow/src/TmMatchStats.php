<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

final class TmMatchStats
{
    public function __construct(
        public readonly int $exact,      // TM score >= autoConfirmThreshold
        public readonly int $fuzzy,      // TM score >= 0.7 but < autoConfirmThreshold
        public readonly int $mt,         // filled by MT adapter
        public readonly int $unmatched,  // no TM match and no MT fill
    ) {}
}
