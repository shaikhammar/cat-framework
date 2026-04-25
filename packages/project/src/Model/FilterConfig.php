<?php

declare(strict_types=1);

namespace CatFramework\Project\Model;

final class FilterConfig
{
    public function __construct(
        /** @var array<string, mixed> */
        public readonly array $docx = [],
        /** @var array<string, mixed> */
        public readonly array $xlsx = [],
    ) {}
}
