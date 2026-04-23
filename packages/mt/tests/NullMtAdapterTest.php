<?php

declare(strict_types=1);

namespace CatFramework\Mt\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Mt\NullMtAdapter;
use PHPUnit\Framework\TestCase;

final class NullMtAdapterTest extends TestCase
{
    private NullMtAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new NullMtAdapter();
    }

    public function testProviderIdIsNull(): void
    {
        $this->assertSame('null', $this->adapter->getProviderId());
    }

    public function testTranslateReturnsEmptySegmentWithSameId(): void
    {
        $source = new Segment('seg-1', ['Hello world']);
        $result = $this->adapter->translate($source, 'en', 'de');

        $this->assertSame('seg-1', $result->id);
        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $result->getElements());
    }

    public function testTranslateStripsInlineCodes(): void
    {
        $segment = new Segment('seg-2', [
            new InlineCode('b', InlineCodeType::OPENING, '<b>'),
            'Bold text',
            new InlineCode('b', InlineCodeType::CLOSING, '</b>'),
        ]);

        $result = $this->adapter->translate($segment, 'en', 'de');
        $this->assertSame([], $result->getElements());
    }

    public function testTranslateBatchPreservesOrder(): void
    {
        $sources = [
            new Segment('s1', ['First']),
            new Segment('s2', ['Second']),
            new Segment('s3', ['Third']),
        ];

        $results = $this->adapter->translateBatch($sources, 'en', 'de');

        $this->assertCount(3, $results);
        $this->assertSame('s1', $results[0]->id);
        $this->assertSame('s2', $results[1]->id);
        $this->assertSame('s3', $results[2]->id);

        foreach ($results as $result) {
            $this->assertSame([], $result->getElements());
        }
    }

    public function testTranslateBatchWithEmptyInputReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->adapter->translateBatch([], 'en', 'de'));
    }
}
