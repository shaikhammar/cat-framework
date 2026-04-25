<?php

declare(strict_types=1);

namespace CatFramework\Project\Tests\Model;

use CatFramework\Project\Model\FilterConfig;
use CatFramework\Project\Model\GlossaryConfig;
use CatFramework\Project\Model\MtConfig;
use CatFramework\Project\Model\ProjectManifest;
use CatFramework\Project\Model\QaConfig;
use CatFramework\Project\Model\TmConfig;
use PHPUnit\Framework\TestCase;

final class ConfigValueObjectsTest extends TestCase
{
    public function test_tm_config_stores_properties(): void
    {
        $tm = new TmConfig('tm/main.db', false);
        $this->assertSame('tm/main.db', $tm->path);
        $this->assertFalse($tm->readOnly);
    }

    public function test_glossary_config_stores_properties(): void
    {
        $g = new GlossaryConfig('glossaries/main.db', true);
        $this->assertSame('glossaries/main.db', $g->path);
        $this->assertTrue($g->readOnly);
    }

    public function test_mt_config_stores_properties(): void
    {
        $mt = new MtConfig('deepl', 'secret-key', 0.75);
        $this->assertSame('deepl', $mt->adapter);
        $this->assertSame('secret-key', $mt->apiKey);
        $this->assertSame(0.75, $mt->fillThreshold);
    }

    public function test_qa_config_stores_properties(): void
    {
        $qa = new QaConfig(['TagConsistencyCheck'], 'error');
        $this->assertSame(['TagConsistencyCheck'], $qa->checks);
        $this->assertSame('error', $qa->failOnSeverity);
    }

    public function test_qa_config_accepts_null_severity(): void
    {
        $qa = new QaConfig([], null);
        $this->assertNull($qa->failOnSeverity);
    }

    public function test_filter_config_defaults_to_empty(): void
    {
        $f = new FilterConfig();
        $this->assertSame([], $f->docx);
        $this->assertSame([], $f->xlsx);
    }

    public function test_project_manifest_stores_all_properties(): void
    {
        $manifest = new ProjectManifest(
            name: 'my-project',
            sourceLang: 'en-US',
            targetLangs: ['fr-FR', 'de-DE'],
            tm: [new TmConfig('tm/main.db', false)],
            glossaries: [],
            mt: null,
            qa: new QaConfig([], null),
            filters: new FilterConfig(),
            basePath: '/tmp/project',
        );

        $this->assertSame('my-project', $manifest->name);
        $this->assertSame('en-US', $manifest->sourceLang);
        $this->assertSame(['fr-FR', 'de-DE'], $manifest->targetLangs);
        $this->assertCount(1, $manifest->tm);
        $this->assertNull($manifest->mt);
        $this->assertSame('/tmp/project', $manifest->basePath);
    }
}
