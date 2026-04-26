<?php

declare(strict_types=1);

namespace CatFramework\Project\Store;

interface SkeletonStoreInterface
{
    /**
     * Store skeleton bytes and return an opaque handle for later retrieval.
     * The handle is whatever the adapter needs to locate the data (file path, DB key, etc.).
     */
    public function store(string $fileId, string $format, string $skeletonBytes): string;

    /** Retrieve skeleton bytes by the handle returned from store(). */
    public function retrieve(string $handle): string;

    /** Delete stored skeleton (call on project or file deletion). */
    public function delete(string $handle): void;
}
