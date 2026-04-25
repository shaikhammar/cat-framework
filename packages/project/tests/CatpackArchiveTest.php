<?php

declare(strict_types=1);

namespace CatFramework\Project\Tests;

use CatFramework\Project\CatpackArchive;
use CatFramework\Project\Exception\ManifestException;
use CatFramework\Project\Model\FilterConfig;
use CatFramework\Project\Model\GlossaryConfig;
use CatFramework\Project\Model\MtConfig;
use CatFramework\Project\Model\ProjectManifest;
use CatFramework\Project\Model\QaConfig;
use CatFramework\Project\Model\TmConfig;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class CatpackArchiveTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cat-archive-test-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeManifest(?MtConfig $mt = null): ProjectManifest
    {
        return new ProjectManifest(
            name: 'test-project',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR'],
            tm: [new TmConfig('tm/main.db', false)],
            glossaries: [],
            mt: $mt,
            qa: new QaConfig(['TagConsistencyCheck'], 'error'),
            filters: new FilterConfig(['mergeSplitRuns' => true]),
            basePath: $this->tmpDir,
        );
    }

    // -------------------------------------------------------------------------
    // create + save + open round-trip
    // -------------------------------------------------------------------------

    public function test_create_and_save_produces_zip_with_manifest(): void
    {
        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->save();

        $this->assertFileExists($outPath);

        $zip = new ZipArchive();
        $zip->open($outPath);
        $this->assertNotFalse($zip->getFromName('catproject.json'));
        $zip->close();
    }

    public function test_open_reads_manifest_from_zip(): void
    {
        $outPath = $this->tmpDir . '/test.catpack';
        $manifest = $this->makeManifest();
        CatpackArchive::create($outPath, $manifest)->save();

        $opened = CatpackArchive::open($outPath);
        $loaded = $opened->getManifest();

        $this->assertSame('test-project', $loaded->name);
        $this->assertSame('en-US', $loaded->sourceLang);
        $this->assertSame(['fr-FR'], $loaded->targetLangs);
        $this->assertCount(1, $loaded->tm);
        $this->assertSame('tm/main.db', $loaded->tm[0]->path);
        $this->assertFalse($loaded->tm[0]->readOnly);
        $this->assertSame(['TagConsistencyCheck'], $loaded->qa->checks);
        $this->assertSame('error', $loaded->qa->failOnSeverity);
        $this->assertSame(['mergeSplitRuns' => true], $loaded->filters->docx);
    }

    public function test_open_preserves_mt_config(): void
    {
        $outPath = $this->tmpDir . '/test.catpack';
        $mt = new MtConfig('deepl', 'api-key-123', 0.75);
        CatpackArchive::create($outPath, $this->makeManifest($mt))->save();

        $loaded = CatpackArchive::open($outPath)->getManifest();

        $this->assertNotNull($loaded->mt);
        $this->assertSame('deepl', $loaded->mt->adapter);
        $this->assertSame('api-key-123', $loaded->mt->apiKey);
        $this->assertSame(0.75, $loaded->mt->fillThreshold);
    }

    public function test_open_throws_manifest_exception_for_missing_catproject_json(): void
    {
        $outPath = $this->tmpDir . '/empty.catpack';
        $zip = new ZipArchive();
        $zip->open($outPath, ZipArchive::CREATE);
        $zip->addFromString('README.txt', 'not a catpack');
        $zip->close();

        $this->expectException(ManifestException::class);
        CatpackArchive::open($outPath);
    }

    public function test_open_throws_manifest_exception_for_non_existent_file(): void
    {
        $this->expectException(ManifestException::class);
        CatpackArchive::open($this->tmpDir . '/nonexistent.catpack');
    }

    // -------------------------------------------------------------------------
    // addSourceFile, addTm, addGlossary, addXliff
    // -------------------------------------------------------------------------

    public function test_add_source_file_places_file_in_source_directory(): void
    {
        $srcFile = $this->tmpDir . '/document.docx';
        file_put_contents($srcFile, 'fake docx content');

        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->addSourceFile($srcFile);
        $archive->save();

        $zip = new ZipArchive();
        $zip->open($outPath);
        $this->assertNotFalse($zip->getFromName('source/document.docx'));
        $zip->close();
    }

    public function test_add_source_file_accepts_custom_archive_name(): void
    {
        $srcFile = $this->tmpDir . '/document.docx';
        file_put_contents($srcFile, 'fake docx content');

        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->addSourceFile($srcFile, 'renamed.docx');
        $archive->save();

        $zip = new ZipArchive();
        $zip->open($outPath);
        $this->assertNotFalse($zip->getFromName('source/renamed.docx'));
        $zip->close();
    }

    public function test_add_tm_places_file_in_tm_directory_with_store_compression(): void
    {
        $dbFile = $this->tmpDir . '/main.db';
        file_put_contents($dbFile, str_repeat('x', 1024));

        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->addTm($dbFile, 'main.db');
        $archive->save();

        $zip = new ZipArchive();
        $zip->open($outPath);
        $stat = $zip->statName('tm/main.db');
        $zip->close();

        $this->assertNotFalse($stat);
        $this->assertSame(0, $stat['comp_method'], 'TM files must use CM_STORE (compression method 0)');
    }

    public function test_add_glossary_places_file_in_glossaries_directory_with_store_compression(): void
    {
        $dbFile = $this->tmpDir . '/terms.db';
        file_put_contents($dbFile, str_repeat('y', 1024));

        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->addGlossary($dbFile, 'terms.db');
        $archive->save();

        $zip = new ZipArchive();
        $zip->open($outPath);
        $stat = $zip->statName('glossaries/terms.db');
        $zip->close();

        $this->assertNotFalse($stat);
        $this->assertSame(0, $stat['comp_method'], 'Glossary files must use CM_STORE (compression method 0)');
    }

    public function test_add_xliff_places_file_in_xliff_directory(): void
    {
        $xliffFile = $this->tmpDir . '/document.xlf';
        file_put_contents($xliffFile, '<xliff/>');

        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->addXliff($xliffFile, 'document.xlf');
        $archive->save();

        $zip = new ZipArchive();
        $zip->open($outPath);
        $this->assertNotFalse($zip->getFromName('xliff/document.xlf'));
        $zip->close();
    }

    // -------------------------------------------------------------------------
    // extractTo
    // -------------------------------------------------------------------------

    public function test_extract_to_produces_correct_directory_layout(): void
    {
        $srcFile = $this->tmpDir . '/doc.docx';
        $dbFile  = $this->tmpDir . '/main.db';
        file_put_contents($srcFile, 'docx');
        file_put_contents($dbFile, 'sqlite');

        $outPath = $this->tmpDir . '/test.catpack';
        $archive = CatpackArchive::create($outPath, $this->makeManifest());
        $archive->addSourceFile($srcFile);
        $archive->addTm($dbFile, 'main.db');
        $archive->save();

        $extractDir = $this->tmpDir . '/extracted';
        mkdir($extractDir);
        CatpackArchive::open($outPath)->extractTo($extractDir);

        $this->assertFileExists($extractDir . '/catproject.json');
        $this->assertFileExists($extractDir . '/source/doc.docx');
        $this->assertFileExists($extractDir . '/tm/main.db');
    }
}
