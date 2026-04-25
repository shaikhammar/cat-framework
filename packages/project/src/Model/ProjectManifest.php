<?php

declare(strict_types=1);

namespace CatFramework\Project\Model;

final class ProjectManifest
{
    public function __construct(
        public readonly string $name,
        public readonly string $sourceLang,
        /** @var string[] */
        public readonly array $targetLangs,
        /** @var TmConfig[] */
        public readonly array $tm,
        /** @var GlossaryConfig[] */
        public readonly array $glossaries,
        public readonly ?MtConfig $mt,
        public readonly QaConfig $qa,
        public readonly FilterConfig $filters,
        public readonly string $basePath,
    ) {}
}
