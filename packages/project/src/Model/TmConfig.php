<?php

declare(strict_types=1);

namespace CatFramework\Project\Model;

final class TmConfig
{
    public function __construct(
        public readonly string $path,
        public readonly bool $readOnly,
    ) {}
}
