<?php

declare(strict_types=1);

namespace CatFramework\Project\Model;

final class QaConfig
{
    public function __construct(
        /** @var string[] */
        public readonly array $checks,
        public readonly ?string $failOnSeverity,
    ) {}
}
