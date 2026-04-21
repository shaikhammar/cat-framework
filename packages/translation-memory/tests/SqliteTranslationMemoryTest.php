<?php

declare(strict_types=1);

namespace CatFramework\TranslationMemory\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Enum\MatchType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\TranslationUnit;
use CatFramework\TranslationMemory\SqliteTranslationMemory;
use PHPUnit\Framework\TestCase;

class SqliteTranslationMemoryTest extends TestCase
{
    private \PDO $pdo;
    private SqliteTranslationMemory $tm;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/tm_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);

        $this->pdo = new \PDO('sqlite::memory:');
        $this->tm  = new SqliteTranslationMemory($this->pdo);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    // --- store and exact lookup ---

    public function test_store_and_exact_lookup(): void
    {
        $this->tm->store($this->makeUnit('Hello world.', 'Bonjour le monde.'));

        $results = $this->tm->lookup(
            new Segment('q', ['Hello world.']),
            'en-US', 'fr-FR',
        );

        $this->assertCount(1, $results);
        $this->assertEqualsWithDelta(1.0, $results[0]->score, 0.001);
        $this->assertSame(MatchType::EXACT, $results[0]->type);
        $this->assertSame('Bonjour le monde.', $results[0]->translationUnit->target->getPlainText());
    }

    public function test_exact_match_is_case_insensitive(): void
    {
        $this->tm->store($this->makeUnit('Hello world.', 'Bonjour le monde.'));

        // Lookup with different casing — normalization should make it match
        $results = $this->tm->lookup(
            new Segment('q', ['HELLO WORLD.']),
            'en-US', 'fr-FR',
        );

        $this->assertCount(1, $results);
        $this->assertEqualsWithDelta(1.0, $results[0]->score, 0.001);
    }

    public function test_no_match_below_threshold(): void
    {
        $this->tm->store($this->makeUnit('Hello world.', 'Bonjour le monde.'));

        $results = $this->tm->lookup(
            new Segment('q', ['Completely different text that shares nothing.']),
            'en-US', 'fr-FR',
        );

        $this->assertCount(0, $results);
    }

    public function test_no_match_for_wrong_language_pair(): void
    {
        $this->tm->store($this->makeUnit('Hello world.', 'Bonjour le monde.'));

        $results = $this->tm->lookup(
            new Segment('q', ['Hello world.']),
            'en-US', 'de-DE', // different target language
        );

        $this->assertCount(0, $results);
    }

    // --- fuzzy lookup ---

    public function test_fuzzy_match_returned_above_threshold(): void
    {
        $this->tm->store($this->makeUnit(
            'Click the Save button to save your changes.',
            'Cliquez sur le bouton Enregistrer pour sauvegarder vos modifications.',
        ));

        $results = $this->tm->lookup(
            new Segment('q', ['Click the OK button to save your changes.']),
            'en-US', 'fr-FR', 0.7,
        );

        $this->assertCount(1, $results);
        $this->assertSame(MatchType::FUZZY, $results[0]->type);
        $this->assertGreaterThan(0.7, $results[0]->score);
        $this->assertLessThan(1.0, $results[0]->score);
    }

    public function test_results_sorted_by_score_descending(): void
    {
        $this->tm->store($this->makeUnit('The cat sat on the mat.', 'Le chat était assis sur le tapis.'));
        $this->tm->store($this->makeUnit('The cat sat on the floor.', 'Le chat était assis sur le sol.'));
        $this->tm->store($this->makeUnit('The dog sat on the mat.', 'Le chien était assis sur le tapis.'));

        $results = $this->tm->lookup(
            new Segment('q', ['The cat sat on the mat.']),
            'en-US', 'fr-FR', 0.5,
        );

        $this->assertGreaterThan(1, count($results));
        for ($i = 1; $i < count($results); $i++) {
            $this->assertGreaterThanOrEqual($results[$i]->score, $results[$i - 1]->score);
        }
    }

    public function test_max_results_limit_is_respected(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->tm->store($this->makeUnit("Hello world sentence {$i}.", "Bonjour le monde phrase {$i}."));
        }

        $results = $this->tm->lookup(
            new Segment('q', ['Hello world sentence 1.']),
            'en-US', 'fr-FR', 0.5, 3,
        );

        $this->assertLessThanOrEqual(3, count($results));
    }

    // --- upsert (most-recent-wins) ---

    public function test_store_updates_existing_entry_with_same_source(): void
    {
        $this->tm->store($this->makeUnit('Hello world.', 'Bonjour le monde.'));
        $this->tm->store($this->makeUnit('Hello world.', 'Salut le monde.')); // updated translation

        $results = $this->tm->lookup(new Segment('q', ['Hello world.']), 'en-US', 'fr-FR');

        $this->assertCount(1, $results); // still only one entry
        $this->assertSame('Salut le monde.', $results[0]->translationUnit->target->getPlainText());
    }

    // --- match type classification ---

    public function test_exact_text_match_when_codes_differ(): void
    {
        // Store with bold tag
        $storedElements = [
            new InlineCode('b1', InlineCodeType::OPENING, '<b>'),
            'Hello world.',
            new InlineCode('b1', InlineCodeType::CLOSING, '</b>'),
        ];
        $this->tm->store($this->makeUnitWithElements($storedElements, ['Bonjour le monde.']));

        // Query without bold tag — same plain text
        $results = $this->tm->lookup(
            new Segment('q', ['Hello world.']),
            'en-US', 'fr-FR',
        );

        $this->assertCount(1, $results);
        $this->assertSame(MatchType::EXACT_TEXT, $results[0]->type);
        $this->assertEqualsWithDelta(1.0, $results[0]->score, 0.001);
    }

    // --- last_used_at updated on lookup ---

    public function test_last_used_at_updated_after_lookup(): void
    {
        $before = new \DateTimeImmutable('-1 minute');
        $this->tm->store($this->makeUnit('Hello world.', 'Bonjour le monde.'));

        $this->tm->lookup(new Segment('q', ['Hello world.']), 'en-US', 'fr-FR');

        $row = $this->pdo->query('SELECT last_used_at FROM translation_units LIMIT 1')->fetch();
        $lastUsed = new \DateTimeImmutable($row['last_used_at']);

        $this->assertGreaterThan($before, $lastUsed);
    }

    // --- inline codes preserved through store/lookup ---

    public function test_inline_codes_survive_store_and_lookup(): void
    {
        $elements = [
            'Hello ',
            new InlineCode('b1', InlineCodeType::OPENING,  '<b>',  '<b>'),
            'world',
            new InlineCode('b1', InlineCodeType::CLOSING,  '</b>', '</b>'),
            '.',
        ];
        $this->tm->store($this->makeUnitWithElements($elements, ['Bonjour le monde.']));

        $results = $this->tm->lookup(new Segment('q', ['Hello world.']), 'en-US', 'fr-FR');

        $this->assertCount(1, $results);
        $codes = $results[0]->translationUnit->source->getInlineCodes();
        $this->assertCount(2, $codes);
        $this->assertSame(InlineCodeType::OPENING, $codes[0]->type);
        $this->assertSame('<b>',                   $codes[0]->data);
        $this->assertSame('<b>',                   $codes[0]->displayText);
    }

    // --- unicode ---

    public function test_hindi_text_exact_match(): void
    {
        $source = 'यह पहला वाक्य है।';
        $target = 'This is the first sentence.';
        $this->tm->store($this->makeUnit($source, $target, 'hi-IN', 'en-US'));

        $results = $this->tm->lookup(new Segment('q', [$source]), 'hi-IN', 'en-US');

        $this->assertCount(1, $results);
        $this->assertEqualsWithDelta(1.0, $results[0]->score, 0.001);
        $this->assertSame($target, $results[0]->translationUnit->target->getPlainText());
    }

    public function test_urdu_fuzzy_match(): void
    {
        $this->tm->store($this->makeUnit('یہ پہلا جملہ ہے۔', 'This is the first sentence.', 'ur-PK', 'en-US'));

        $results = $this->tm->lookup(
            new Segment('q', ['یہ دوسرا جملہ ہے۔']),
            'ur-PK', 'en-US', 0.5,
        );

        // Should find a fuzzy match since the sentences are similar
        $this->assertGreaterThan(0, count($results));
        if (count($results) > 0) {
            $this->assertSame(MatchType::FUZZY, $results[0]->type);
        }
    }

    // --- import / export ---

    public function test_import_from_tmx_file(): void
    {
        $tmxPath = $this->writeTmxFixture([
            ['Hello world.', 'Bonjour le monde.'],
            ['Second sentence.', 'Deuxième phrase.'],
        ]);

        $count = $this->tm->import($tmxPath);

        $this->assertSame(2, $count);

        $results = $this->tm->lookup(new Segment('q', ['Hello world.']), 'en-US', 'fr-FR');
        $this->assertCount(1, $results);
        $this->assertEqualsWithDelta(1.0, $results[0]->score, 0.001);
    }

    public function test_export_to_tmx_file(): void
    {
        $this->tm->store($this->makeUnit('Hello world.', 'Bonjour le monde.'));
        $this->tm->store($this->makeUnit('Second sentence.', 'Deuxième phrase.'));

        $tmxPath = $this->tmpDir . '/export.tmx';
        $count   = $this->tm->export($tmxPath);

        $this->assertSame(2, $count);
        $this->assertFileExists($tmxPath);
        $this->assertStringContainsString('Hello world.', file_get_contents($tmxPath));
    }

    public function test_import_then_export_round_trip(): void
    {
        $original = $this->writeTmxFixture([
            ['First sentence.', 'Première phrase.'],
            ['Second sentence.', 'Deuxième phrase.'],
        ]);

        $this->tm->import($original);

        $exported = $this->tmpDir . '/exported.tmx';
        $count    = $this->tm->export($exported);

        $this->assertSame(2, $count);

        // Re-import into a fresh TM and verify
        $tm2    = new SqliteTranslationMemory(new \PDO('sqlite::memory:'));
        $count2 = $tm2->import($exported);

        $this->assertSame(2, $count2);

        $results = $tm2->lookup(new Segment('q', ['First sentence.']), 'en-US', 'fr-FR');
        $this->assertCount(1, $results);
        $this->assertSame('Première phrase.', $results[0]->translationUnit->target->getPlainText());
    }

    public function test_empty_segment_lookup_returns_no_results(): void
    {
        $this->tm->store($this->makeUnit('Hello world.', 'Bonjour le monde.'));

        $results = $this->tm->lookup(new Segment('q', []), 'en-US', 'fr-FR');

        $this->assertCount(0, $results);
    }

    // --- helpers ---

    private function makeUnit(
        string $sourceText,
        string $targetText,
        string $sourceLang = 'en-US',
        string $targetLang = 'fr-FR',
    ): TranslationUnit {
        return new TranslationUnit(
            source:         new Segment('src-' . uniqid(), [$sourceText]),
            target:         new Segment('tgt-' . uniqid(), [$targetText]),
            sourceLanguage: $sourceLang,
            targetLanguage: $targetLang,
            createdAt:      new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    private function makeUnitWithElements(array $sourceElements, array $targetElements): TranslationUnit
    {
        return new TranslationUnit(
            source:         new Segment('src-' . uniqid(), $sourceElements),
            target:         new Segment('tgt-' . uniqid(), $targetElements),
            sourceLanguage: 'en-US',
            targetLanguage: 'fr-FR',
            createdAt:      new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /** @param array<array{string,string}> $pairs */
    private function writeTmxFixture(array $pairs): string
    {
        $path  = $this->tmpDir . '/fixture_' . uniqid() . '.tmx';
        $units = array_map(fn($p) => $this->makeUnit($p[0], $p[1]), $pairs);

        (new \CatFramework\Tmx\TmxWriter())->write($units, $path);

        return $path;
    }
}
