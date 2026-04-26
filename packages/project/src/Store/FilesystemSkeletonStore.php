<?php

declare(strict_types=1);

namespace CatFramework\Project\Store;

use CatFramework\Project\Exception\ProjectException;

/**
 * Stores skeleton bytes as a file on disk.
 * The handle returned by store() is the absolute file path, which retrieve() reads back.
 * This matches the existing behaviour where filters wrote .skl files next to XLIFF output.
 */
class FilesystemSkeletonStore implements SkeletonStoreInterface
{
    public function __construct(private readonly string $outputDir)
    {
        if (!is_dir($this->outputDir)) {
            throw new ProjectException("Skeleton output directory does not exist: {$this->outputDir}");
        }
    }

    public function store(string $fileId, string $format, string $skeletonBytes): string
    {
        $path = rtrim($this->outputDir, '/\\') . DIRECTORY_SEPARATOR . $fileId . '.skl';

        if (file_put_contents($path, $skeletonBytes) === false) {
            throw new ProjectException("Cannot write skeleton to: {$path}");
        }

        return $path;
    }

    public function retrieve(string $handle): string
    {
        if (!is_file($handle)) {
            throw new ProjectException("Skeleton file not found: {$handle}");
        }

        $bytes = file_get_contents($handle);

        if ($bytes === false) {
            throw new ProjectException("Cannot read skeleton from: {$handle}");
        }

        return $bytes;
    }

    public function delete(string $handle): void
    {
        if (is_file($handle)) {
            unlink($handle);
        }
    }
}
