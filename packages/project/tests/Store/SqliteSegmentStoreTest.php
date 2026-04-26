<?php

declare(strict_types=1);

namespace CatFramework\Project\Tests\Store;

use CatFramework\Project\Store\SegmentStoreInterface;
use CatFramework\Project\Store\SqliteSegmentStore;

class SqliteSegmentStoreTest extends SegmentStoreContractTest
{
    protected function makeStore(): SegmentStoreInterface
    {
        return new SqliteSegmentStore(new \PDO('sqlite::memory:'));
    }
}
