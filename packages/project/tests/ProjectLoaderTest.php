<?php

declare(strict_types=1);

namespace CatFramework\Project\Tests;

use CatFramework\Project\Exception\ManifestException;
use CatFramework\Project\Model\ProjectManifest;
use CatFramework\Project\ProjectLoader;
use PHPUnit\Framework\TestCase;

final class ProjectLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cat-project-test-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*'));
        rmdir($this->tmpDir);
    }

    private function writeManifest(array $data): string
    {
        $path = $this->tmpDir . '/catproject.json';
        file_put_contents($path, json_encode($data));
        return $path;
    }

    private function minimalManifest(): array
    {
        return [
            'name' => 'test-project',
            'sourceLang' => 'en-US',
            'targetLangs' => ['fr-FR'],
            'tm' => [],
            'glossaries' => [],
            'qa' => ['checks' => [], 'failOnSeverity' => null],
        ];
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_loads_minimal_manifest(): void
    {
        $path = $this->writeManifest($this->minimalManifest());
        $manifest = (new ProjectLoader($path))->getManifest();

        $this->assertInstanceOf(ProjectManifest::class, $manifest);
        $this->assertSame('test-project', $manifest->name);
        $this->assertSame('en-US', $manifest->sourceLang);
        $this->assertSame(['fr-FR'], $manifest->targetLangs);
        $this->assertSame([], $manifest->tm);
        $this->assertSame([], $manifest->glossaries);
        $this->assertNull($manifest->mt);
        $this->assertSame($this->tmpDir, $manifest->basePath);
    }

    public function test_loads_tm_entries(): void
    {
        $data = $this->minimalManifest();
        $data['tm'] = [
            ['path' => 'tm/main.db', 'readOnly' => false],
            ['path' => 'tm/ref.db', 'readOnly' => true],
        ];
        $manifest = (new ProjectLoader($this->writeManifest($data)))->getManifest();

        $this->assertCount(2, $manifest->tm);
        $this->assertSame('tm/main.db', $manifest->tm[0]->path);
        $this->assertFalse($manifest->tm[0]->readOnly);
        $this->assertTrue($manifest->tm[1]->readOnly);
    }

    public function test_loads_mt_config(): void
    {
        $data = $this->minimalManifest();
        $data['mt'] = ['adapter' => 'deepl', 'apiKey' => 'abc123', 'fillThreshold' => 0.75];
        $manifest = (new ProjectLoader($this->writeManifest($data)))->getManifest();

        $this->assertNotNull($manifest->mt);
        $this->assertSame('deepl', $manifest->mt->adapter);
        $this->assertSame('abc123', $manifest->mt->apiKey);
        $this->assertSame(0.75, $manifest->mt->fillThreshold);
    }

    public function test_loads_qa_config(): void
    {
        $data = $this->minimalManifest();
        $data['qa'] = ['checks' => ['TagConsistencyCheck', 'EmptyTranslationCheck'], 'failOnSeverity' => 'error'];
        $manifest = (new ProjectLoader($this->writeManifest($data)))->getManifest();

        $this->assertSame(['TagConsistencyCheck', 'EmptyTranslationCheck'], $manifest->qa->checks);
        $this->assertSame('error', $manifest->qa->failOnSeverity);
    }

    public function test_filters_default_to_empty_when_absent(): void
    {
        $manifest = (new ProjectLoader($this->writeManifest($this->minimalManifest())))->getManifest();

        $this->assertSame([], $manifest->filters->docx);
        $this->assertSame([], $manifest->filters->xlsx);
    }

    public function test_loads_filter_overrides(): void
    {
        $data = $this->minimalManifest();
        $data['filters'] = ['docx' => ['mergeSplitRuns' => true], 'xlsx' => ['skipEmptyCells' => false]];
        $manifest = (new ProjectLoader($this->writeManifest($data)))->getManifest();

        $this->assertSame(['mergeSplitRuns' => true], $manifest->filters->docx);
        $this->assertSame(['skipEmptyCells' => false], $manifest->filters->xlsx);
    }

    // -------------------------------------------------------------------------
    // Validation — missing required fields
    // -------------------------------------------------------------------------

    public function test_throws_manifest_exception_for_missing_name(): void
    {
        $data = $this->minimalManifest();
        unset($data['name']);
        $this->expectException(ManifestException::class);
        (new ProjectLoader($this->writeManifest($data)))->getManifest();
    }

    public function test_throws_manifest_exception_for_missing_source_lang(): void
    {
        $data = $this->minimalManifest();
        unset($data['sourceLang']);
        $this->expectException(ManifestException::class);
        (new ProjectLoader($this->writeManifest($data)))->getManifest();
    }

    public function test_throws_manifest_exception_for_missing_target_langs(): void
    {
        $data = $this->minimalManifest();
        unset($data['targetLangs']);
        $this->expectException(ManifestException::class);
        (new ProjectLoader($this->writeManifest($data)))->getManifest();
    }

    public function test_throws_manifest_exception_for_empty_target_langs(): void
    {
        $data = $this->minimalManifest();
        $data['targetLangs'] = [];
        $this->expectException(ManifestException::class);
        (new ProjectLoader($this->writeManifest($data)))->getManifest();
    }

    public function test_throws_manifest_exception_for_invalid_json(): void
    {
        $path = $this->tmpDir . '/catproject.json';
        file_put_contents($path, 'not json {{{');
        $this->expectException(ManifestException::class);
        (new ProjectLoader($path))->getManifest();
    }

    public function test_throws_manifest_exception_for_missing_file(): void
    {
        $this->expectException(ManifestException::class);
        (new ProjectLoader($this->tmpDir . '/nonexistent.json'))->getManifest();
    }

    // -------------------------------------------------------------------------
    // Env var resolution
    // -------------------------------------------------------------------------

    public function test_resolves_env_var_in_api_key(): void
    {
        putenv('TEST_DEEPL_KEY=secret-from-env');
        $data = $this->minimalManifest();
        $data['mt'] = ['adapter' => 'deepl', 'apiKey' => '${TEST_DEEPL_KEY}', 'fillThreshold' => 0.0];
        $manifest = (new ProjectLoader($this->writeManifest($data)))->getManifest();
        putenv('TEST_DEEPL_KEY');

        $this->assertSame('secret-from-env', $manifest->mt->apiKey);
    }

    public function test_leaves_unresolved_env_var_literal_when_not_set(): void
    {
        putenv('UNSET_VAR_XYZ');
        $data = $this->minimalManifest();
        $data['mt'] = ['adapter' => 'deepl', 'apiKey' => '${UNSET_VAR_XYZ}', 'fillThreshold' => 0.0];
        $manifest = (new ProjectLoader($this->writeManifest($data)))->getManifest();

        $this->assertSame('${UNSET_VAR_XYZ}', $manifest->mt->apiKey);
    }

    // -------------------------------------------------------------------------
    // resolvePath
    // -------------------------------------------------------------------------

    public function test_resolve_path_joins_relative_to_base(): void
    {
        $result = ProjectLoader::resolvePath('/projects/myproject', 'tm/main.db');
        $this->assertSame('/projects/myproject' . DIRECTORY_SEPARATOR . 'tm/main.db', $result);
    }

    public function test_resolve_path_returns_absolute_path_unchanged(): void
    {
        $result = ProjectLoader::resolvePath('/projects/myproject', '/absolute/path/tm.db');
        $this->assertSame('/absolute/path/tm.db', $result);
    }
}
