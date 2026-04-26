<?php

declare(strict_types=1);

namespace CatFramework\Project\Tests\Store;

use CatFramework\Project\Store\DatabaseSkeletonStore;
use CatFramework\Project\Store\FilesystemSkeletonStore;
use PHPUnit\Framework\TestCase;

class SkeletonStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/skl_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    // ── FilesystemSkeletonStore ───────────────────────────────────────────────

    public function test_filesystem_store_round_trips_bytes(): void
    {
        $store  = new FilesystemSkeletonStore($this->tmpDir);
        $handle = $store->store('file-001', 'docx', 'skeleton bytes here');

        $this->assertSame('skeleton bytes here', $store->retrieve($handle));
    }

    public function test_filesystem_store_creates_file_with_skl_extension(): void
    {
        $store  = new FilesystemSkeletonStore($this->tmpDir);
        $handle = $store->store('file-001', 'docx', 'data');

        $this->assertStringEndsWith('.skl', $handle);
        $this->assertFileExists($handle);
    }

    public function test_filesystem_store_delete_removes_file(): void
    {
        $store  = new FilesystemSkeletonStore($this->tmpDir);
        $handle = $store->store('file-001', 'docx', 'data');
        $store->delete($handle);

        $this->assertFileDoesNotExist($handle);
    }

    public function test_filesystem_delete_of_nonexistent_is_silent(): void
    {
        $store = new FilesystemSkeletonStore($this->tmpDir);
        $store->delete('/no/such/file.skl'); // must not throw
        $this->assertTrue(true);
    }

    // ── DatabaseSkeletonStore ─────────────────────────────────────────────────

    public function test_database_store_round_trips_bytes(): void
    {
        $store  = new DatabaseSkeletonStore(new \PDO('sqlite::memory:'));
        $handle = $store->store('file-001', 'html', 'db skeleton bytes');

        $this->assertSame('db skeleton bytes', $store->retrieve($handle));
    }

    public function test_database_store_returns_file_id_as_handle(): void
    {
        $store  = new DatabaseSkeletonStore(new \PDO('sqlite::memory:'));
        $handle = $store->store('file-abc', 'xlsx', 'data');

        $this->assertSame('file-abc', $handle);
    }

    public function test_database_store_overwrite_replaces_blob(): void
    {
        $store = new DatabaseSkeletonStore(new \PDO('sqlite::memory:'));
        $store->store('file-001', 'docx', 'first');
        $store->store('file-001', 'docx', 'second');

        $this->assertSame('second', $store->retrieve('file-001'));
    }

    public function test_database_store_delete_removes_row(): void
    {
        $store = new DatabaseSkeletonStore(new \PDO('sqlite::memory:'));
        $store->store('file-001', 'docx', 'data');
        $store->delete('file-001');

        $this->expectException(\CatFramework\Project\Exception\ProjectException::class);
        $store->retrieve('file-001');
    }

    public function test_database_stores_unicode_bytes_correctly(): void
    {
        $bytes = 'بسم الله الرحمن الرحيم';
        $store = new DatabaseSkeletonStore(new \PDO('sqlite::memory:'));
        $store->store('file-ur', 'txt', $bytes);

        $this->assertSame($bytes, $store->retrieve('file-ur'));
    }
}
