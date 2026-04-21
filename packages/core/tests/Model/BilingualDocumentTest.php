<?php

declare(strict_types=1);

namespace CatFramework\Core\Tests\Model;

use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;
use PHPUnit\Framework\TestCase;

class BilingualDocumentTest extends TestCase
{
    private function makeDoc(): BilingualDocument
    {
        return new BilingualDocument(
            sourceLanguage: 'en-US',
            targetLanguage: 'hi-IN',
            originalFile: 'test.txt',
            mimeType: 'text/plain',
        );
    }

    public function test_new_document_has_zero_pairs(): void
    {
        $doc = $this->makeDoc();

        $this->assertSame(0, $doc->count());
        $this->assertSame([], $doc->getSegmentPairs());
    }

    public function test_addSegmentPair_increments_count(): void
    {
        $doc  = $this->makeDoc();
        $pair = new SegmentPair(new Segment('s1', ['Hello world']));
        $doc->addSegmentPair($pair);

        $this->assertSame(1, $doc->count());
    }

    public function test_getSegmentPairById_returns_matching_pair(): void
    {
        $doc  = $this->makeDoc();
        $pair = new SegmentPair(new Segment('s1', ['Hello world']));
        $doc->addSegmentPair($pair);

        $this->assertSame($pair, $doc->getSegmentPairById('s1'));
    }

    public function test_getSegmentPairById_returns_null_for_unknown_id(): void
    {
        $doc = $this->makeDoc();

        $this->assertNull($doc->getSegmentPairById('nonexistent'));
    }

    public function test_getSegmentPairs_preserves_insertion_order(): void
    {
        $doc   = $this->makeDoc();
        $pair1 = new SegmentPair(new Segment('s1', ['First']));
        $pair2 = new SegmentPair(new Segment('s2', ['Second']));
        $doc->addSegmentPair($pair1);
        $doc->addSegmentPair($pair2);

        $this->assertSame([$pair1, $pair2], $doc->getSegmentPairs());
    }
}
