<?php

declare(strict_types=1);

namespace CatFramework\Workflow\Tests;

use CatFramework\FilterPlaintext\PlainTextFilter;
use CatFramework\Project\Model\FilterConfig;
use CatFramework\Project\Model\GlossaryConfig;
use CatFramework\Project\Model\MtConfig;
use CatFramework\Project\Model\ProjectManifest;
use CatFramework\Project\Model\QaConfig;
use CatFramework\Project\Model\TmConfig;
use CatFramework\Workflow\Exception\WorkflowException;
use CatFramework\Workflow\FileFilterRegistry;
use CatFramework\Workflow\ProjectWorkflowBuilder;
use CatFramework\Workflow\WorkflowRunner;
use PHPUnit\Framework\TestCase;

final class ProjectWorkflowBuilderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/catfw-workflow-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) {
            is_file($f) && unlink($f);
        }
        rmdir($this->tmpDir);
    }

    private function makeMinimalManifest(): ProjectManifest
    {
        return new ProjectManifest(
            name: 'test',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR'],
            tm: [],
            glossaries: [],
            mt: null,
            qa: new QaConfig(checks: [], failOnSeverity: null),
            filters: new FilterConfig(),
            basePath: $this->tmpDir,
        );
    }

    private function makeRegistry(): FileFilterRegistry
    {
        $registry = new FileFilterRegistry();
        $registry->register(new PlainTextFilter());
        return $registry;
    }

    public function test_build_returns_workflow_runner_for_minimal_manifest(): void
    {
        $builder = new ProjectWorkflowBuilder($this->makeMinimalManifest());
        $runner  = $builder->build('fr-FR', $this->makeRegistry());

        $this->assertInstanceOf(WorkflowRunner::class, $runner);
    }

    public function test_build_with_sqlite_tm_returns_runner(): void
    {
        $dbPath = $this->tmpDir . '/test.db';
        // Create an empty SQLite database (SqliteTranslationMemory will migrate schema)
        $pdo = new \PDO("sqlite:{$dbPath}");
        unset($pdo);

        $manifest = new ProjectManifest(
            name: 'test',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR'],
            tm: [new TmConfig(path: $dbPath, readOnly: false)],
            glossaries: [],
            mt: null,
            qa: new QaConfig(checks: [], failOnSeverity: null),
            filters: new FilterConfig(),
            basePath: $this->tmpDir,
        );

        $builder = new ProjectWorkflowBuilder($manifest);
        $runner  = $builder->build('fr-FR', $this->makeRegistry());

        $this->assertInstanceOf(WorkflowRunner::class, $runner);
    }

    public function test_unknown_mt_adapter_throws_workflow_exception(): void
    {
        $manifest = new ProjectManifest(
            name: 'test',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR'],
            tm: [],
            glossaries: [],
            mt: new MtConfig(adapter: 'nonexistent-adapter', apiKey: 'key', fillThreshold: 0.0),
            qa: new QaConfig(checks: [], failOnSeverity: null),
            filters: new FilterConfig(),
            basePath: $this->tmpDir,
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Unknown MT adapter');

        $builder = new ProjectWorkflowBuilder($manifest);
        $builder->build('fr-FR', $this->makeRegistry());
    }

    public function test_unknown_qa_check_throws_workflow_exception(): void
    {
        $manifest = new ProjectManifest(
            name: 'test',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR'],
            tm: [],
            glossaries: [],
            mt: null,
            qa: new QaConfig(checks: ['NonExistentCheck'], failOnSeverity: null),
            filters: new FilterConfig(),
            basePath: $this->tmpDir,
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Unknown QA check');

        $builder = new ProjectWorkflowBuilder($manifest);
        $builder->build('fr-FR', $this->makeRegistry());
    }

    public function test_build_with_empty_translation_check_returns_runner(): void
    {
        $manifest = new ProjectManifest(
            name: 'test',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR'],
            tm: [],
            glossaries: [],
            mt: null,
            qa: new QaConfig(checks: ['EmptyTranslationCheck'], failOnSeverity: 'error'),
            filters: new FilterConfig(),
            basePath: $this->tmpDir,
        );

        $builder = new ProjectWorkflowBuilder($manifest);
        $runner  = $builder->build('fr-FR', $this->makeRegistry());

        $this->assertInstanceOf(WorkflowRunner::class, $runner);
    }
}
