<?php

declare(strict_types=1);

namespace CatFramework\Workflow;

use CatFramework\Core\Contract\FileFilterInterface;
use CatFramework\Workflow\Exception\WorkflowException;

final class FileFilterRegistry
{
    /** @var FileFilterInterface[] */
    private array $filters = [];

    public function register(FileFilterInterface $filter): void
    {
        $this->filters[] = $filter;
    }

    public function getFilter(string $filePath, ?string $mimeType = null): FileFilterInterface
    {
        foreach ($this->filters as $filter) {
            if ($filter->supports($filePath, $mimeType)) {
                return $filter;
            }
        }

        throw new WorkflowException("No filter found for: {$filePath}");
    }
}
