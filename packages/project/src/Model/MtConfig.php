<?php

declare(strict_types=1);

namespace CatFramework\Project\Model;

final class MtConfig
{
    public function __construct(
        public readonly string $adapter,
        public readonly string $apiKey,
        public readonly float $fillThreshold,
    ) {}
}
