<?php

declare(strict_types=1);

namespace CatFramework\Project\Tests\Store;

use CatFramework\Core\Enum\SegmentStatus;
use CatFramework\Core\Model\BilingualDocument;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\SegmentPair;
use CatFramework\Project\Exception\InvalidStatusTransitionException;
use CatFramework\Project\Store\InMemorySegmentStore;
use CatFramework\Project\Store\SegmentStoreInterface;
use CatFramework\Project\Store\SqliteSegmentStore;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests that run against every SegmentStore adapter.
 * Add new adapters to provideStore() to test them automatically.
 */
abstract class SegmentStoreContractTest extends TestCase
{
    abstract protected function makeStore(): SegmentStoreInterface;

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeDoc(array $phrases): BilingualDocument
    {
        $pairs = [];
        foreach ($phrases as $i => $text) {
            $pairs[] = new SegmentPair(new Segment("s{$i}", [$text]));
        }

        return new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', $pairs);
    }

    // ── persist + count ───────────────────────────────────────────────────────

    public function test_persist_returns_file_id(): void
    {
        $store = $this->makeStore();
        $doc   = $this->makeDoc(['Hello.', 'World.']);

        $result = $store->persist($doc, 'file-001');

        $this->assertSame('file-001', $result);
    }

    public function test_count_segments_after_persist(): void
    {
        $store = $this->makeStore();
        $store->persist($this->makeDoc(['A.', 'B.', 'C.']), 'file-001');

        $this->assertSame(3, $store->countSegments('file-001'));
    }

    public function test_count_returns_zero_for_unknown_file(): void
    {
        $this->assertSame(0, $this->makeStore()->countSegments('no-such-file'));
    }

    // ── getSegments ───────────────────────────────────────────────────────────

    public function test_get_segments_returns_all_for_file(): void
    {
        $store = $this->makeStore();
        $store->persist($this->makeDoc(['First.', 'Second.']), 'file-001');

        $segments = $store->getSegments('file-001');

        $this->assertCount(2, $segments);
        $this->assertSame('First.',  $segments[0]->sourceText);
        $this->assertSame('Second.', $segments[1]->sourceText);
    }

    public function test_get_segments_filters_by_status(): void
    {
        $store = $this->makeStore();
        $doc   = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', [
            new SegmentPair(new Segment('s1', ['Untranslated.']), status: SegmentStatus::Untranslated),
            new SegmentPair(new Segment('s2', ['Translated.']),   status: SegmentStatus::Translated),
        ]);
        $store->persist($doc, 'file-001');

        $translated = $store->getSegments('file-001', SegmentStatus::Translated);

        $this->assertCount(1, $translated);
        $this->assertSame('Translated.', $translated[0]->sourceText);
    }

    public function test_get_segments_respects_limit_and_offset(): void
    {
        $store = $this->makeStore();
        $store->persist($this->makeDoc(['A.', 'B.', 'C.', 'D.', 'E.']), 'file-001');

        $page = $store->getSegments('file-001', null, 2, 2);

        $this->assertCount(2, $page);
        $this->assertSame('C.', $page[0]->sourceText);
        $this->assertSame('D.', $page[1]->sourceText);
    }

    // ── getSegment ────────────────────────────────────────────────────────────

    public function test_get_segment_returns_correct_row(): void
    {
        $store    = $this->makeStore();
        $store->persist($this->makeDoc(['Hello world.']), 'file-001');
        $segments = $store->getSegments('file-001');

        $single = $store->getSegment($segments[0]->id);

        $this->assertSame($segments[0]->id, $single->id);
        $this->assertSame('Hello world.', $single->sourceText);
    }

    // ── updateSegment ─────────────────────────────────────────────────────────

    public function test_update_sets_target_text(): void
    {
        $store    = $this->makeStore();
        $store->persist($this->makeDoc(['Hello.']), 'file-001');
        $id       = $store->getSegments('file-001')[0]->id;

        $store->updateSegment($id, 'Bonjour.', SegmentStatus::Translated);

        $seg = $store->getSegment($id);
        $this->assertSame('Bonjour.', $seg->targetText);
        $this->assertSame(SegmentStatus::Translated, $seg->status);
    }

    public function test_update_status_only_preserves_target_text(): void
    {
        $store = $this->makeStore();
        $store->persist($this->makeDoc(['Hello.']), 'file-001');
        $id    = $store->getSegments('file-001')[0]->id;
        $store->updateSegment($id, 'Bonjour.', SegmentStatus::Translated);

        $store->updateSegment($id, null, SegmentStatus::Reviewed);

        $seg = $store->getSegment($id);
        $this->assertSame('Bonjour.', $seg->targetText);
        $this->assertSame(SegmentStatus::Reviewed, $seg->status);
    }

    public function test_approved_segment_cannot_be_reopened(): void
    {
        $store = $this->makeStore();
        $store->persist($this->makeDoc(['Hello.']), 'file-001');
        $id    = $store->getSegments('file-001')[0]->id;
        $store->updateSegment($id, 'Bonjour.', SegmentStatus::Approved);

        $this->expectException(InvalidStatusTransitionException::class);
        $store->updateSegment($id, 'Changed.', SegmentStatus::Draft);
    }

    // ── count with status filter ──────────────────────────────────────────────

    public function test_count_with_status_filter(): void
    {
        $store = $this->makeStore();
        $doc   = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', [
            new SegmentPair(new Segment('s1', ['A.']), status: SegmentStatus::Untranslated),
            new SegmentPair(new Segment('s2', ['B.']), status: SegmentStatus::Untranslated),
            new SegmentPair(new Segment('s3', ['C.']), status: SegmentStatus::Translated),
        ]);
        $store->persist($doc, 'file-001');

        $this->assertSame(2, $store->countSegments('file-001', SegmentStatus::Untranslated));
        $this->assertSame(1, $store->countSegments('file-001', SegmentStatus::Translated));
    }

    // ── word count ────────────────────────────────────────────────────────────

    public function test_word_count_is_computed(): void
    {
        $store = $this->makeStore();
        $store->persist($this->makeDoc(['Hello beautiful world.']), 'file-001');

        $seg = $store->getSegments('file-001')[0];

        $this->assertSame(3, $seg->wordCount);
    }

    // ── inline code round-trip ────────────────────────────────────────────────

    public function test_inline_codes_survive_persist_and_retrieve(): void
    {
        $elements = [
            'Click ',
            new InlineCode('a1', InlineCodeType::OPENING, '<a href="#">', 'a'),
            'here',
            new InlineCode('a1', InlineCodeType::CLOSING, '</a>', '/a'),
            '.',
        ];
        $pair  = new SegmentPair(new Segment('s1', $elements));
        $doc   = new BilingualDocument('en-US', 'fr-FR', 'test.txt', 'text/plain', [$pair]);
        $store = $this->makeStore();
        $store->persist($doc, 'file-001');

        $seg = $store->getSegments('file-001')[0];

        $this->assertSame('Click {1}here{/1}.', $seg->sourceText);
        $this->assertCount(2, $seg->sourceTags);
        $this->assertSame('open',  $seg->sourceTags[0]['type']);
        $this->assertSame('close', $seg->sourceTags[1]['type']);
    }

    // ── persistSegment streaming ──────────────────────────────────────────────

    public function test_persist_segment_streams_one_at_a_time(): void
    {
        $store = $this->makeStore();
        $pair  = new SegmentPair(new Segment('s1', ['Streamed.']));

        $store->persistSegment($pair, 1, 'file-stream');

        $this->assertSame(1, $store->countSegments('file-stream'));
        $this->assertSame('Streamed.', $store->getSegments('file-stream')[0]->sourceText);
    }
}
