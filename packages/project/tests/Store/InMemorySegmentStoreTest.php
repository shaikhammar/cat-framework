<?php

declare(strict_types=1);

namespace CatFramework\Project\Tests\Store;

use CatFramework\Project\Store\InMemorySegmentStore;
use CatFramework\Project\Store\SegmentStoreInterface;

class InMemorySegmentStoreTest extends SegmentStoreContractTest
{
    protected function makeStore(): SegmentStoreInterface
    {
        return new InMemorySegmentStore();
    }
}
