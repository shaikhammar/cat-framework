<?php

declare(strict_types=1);

namespace CatFramework\Terminology\Tests\Provider;

use CatFramework\Core\Model\TermEntry;
use CatFramework\Terminology\Provider\SqliteTerminologyProvider;
use PHPUnit\Framework\TestCase;

class SqliteTerminologyProviderTest extends TestCase
{
    private SqliteTerminologyProvider $provider;

    protected function setUp(): void
    {
        // Use in-memory SQLite so tests are isolated and fast.
        $this->provider = new SqliteTerminologyProvider(':memory:');
    }

    // ── addEntry / lookup ─────────────────────────────────────────────────

    public function testAddAndLookupEntry(): void
    {
        $this->provider->addEntry(new TermEntry(
            sourceTerm: 'translation memory',
            targetTerm: 'mémoire de traduction',
            sourceLanguage: 'en',
            targetLanguage: 'fr',
        ));

        $results = $this->provider->lookup('translation memory', 'en', 'fr');

        $this->assertCount(1, $results);
        $this->assertSame('mémoire de traduction', $results[0]->targetTerm);
    }

    public function testLookupIsCaseInsensitive(): void
    {
        $this->provider->addEntry(new TermEntry(
            sourceTerm: 'Translation Memory',
            targetTerm: 'mémoire de traduction',
            sourceLanguage: 'en',
            targetLanguage: 'fr',
        ));

        $this->assertCount(1, $this->provider->lookup('translation memory', 'en', 'fr'));
        $this->assertCount(1, $this->provider->lookup('TRANSLATION MEMORY', 'en', 'fr'));
    }

    public function testLookupReturnsForbiddenEntries(): void
    {
        $this->provider->addEntry(new TermEntry(
            sourceTerm: 'TM',
            targetTerm: 'MT',
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            forbidden: true,
        ));

        $results = $this->provider->lookup('TM', 'en', 'fr');
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->forbidden);
    }

    public function testLookupReturnsEmptyForUnknownLanguagePair(): void
    {
        $this->provider->addEntry(new TermEntry(
            sourceTerm: 'hello',
            targetTerm: 'hola',
            sourceLanguage: 'en',
            targetLanguage: 'es',
        ));

        $this->assertSame([], $this->provider->lookup('hello', 'en', 'fr'));
    }

    // ── recognize ────────────────────────────────────────────────────────

    public function testRecognizesTermInText(): void
    {
        $this->provider->addEntry(new TermEntry(
            sourceTerm: 'translation memory',
            targetTerm: 'mémoire de traduction',
            sourceLanguage: 'en',
            targetLanguage: 'fr',
        ));

        $matches = $this->provider->recognize(
            'Use the translation memory to improve consistency.',
            'en',
            'fr'
        );

        $this->assertCount(1, $matches);
        $this->assertSame('translation memory', $matches[0]->entry->sourceTerm);
        $this->assertSame(8, $matches[0]->offset);
    }

    public function testRecognizeIsCaseInsensitive(): void
    {
        $this->provider->addEntry(new TermEntry(
            sourceTerm: 'translation memory',
            targetTerm: 'mémoire de traduction',
            sourceLanguage: 'en',
            targetLanguage: 'fr',
        ));

        $matches = $this->provider->recognize('Use the Translation Memory here.', 'en', 'fr');
        $this->assertCount(1, $matches);
    }

    public function testRecognizeEnforcesWordBoundary(): void
    {
        $this->provider->addEntry(new TermEntry(
            sourceTerm: 'cat',
            targetTerm: 'chat',
            sourceLanguage: 'en',
            targetLanguage: 'fr',
        ));

        // "concatenate" contains "cat" but is not a word boundary match.
        $matches = $this->provider->recognize('concatenate these strings', 'en', 'fr');
        $this->assertCount(0, $matches);

        // Standalone "cat" should match.
        $matches = $this->provider->recognize('The cat sat on the mat.', 'en', 'fr');
        $this->assertCount(1, $matches);
    }

    public function testRecognizeMultipleMatches(): void
    {
        $this->provider->addEntry(new TermEntry(
            sourceTerm: 'segment',
            targetTerm: 'segment',
            sourceLanguage: 'en',
            targetLanguage: 'fr',
        ));

        $matches = $this->provider->recognize('Each segment must be a valid segment.', 'en', 'fr');
        $this->assertCount(2, $matches);
        $this->assertSame(5, $matches[0]->offset);
        $this->assertSame(29, $matches[1]->offset);
    }

    public function testRecognizeResultsSortedByOffset(): void
    {
        $this->provider->addEntry(new TermEntry('alpha', 'alpha_fr', 'en', 'fr'));
        $this->provider->addEntry(new TermEntry('beta', 'beta_fr', 'en', 'fr'));

        $matches = $this->provider->recognize('beta then alpha', 'en', 'fr');
        $this->assertCount(2, $matches);
        $this->assertSame('beta', $matches[0]->entry->sourceTerm);
        $this->assertSame('alpha', $matches[1]->entry->sourceTerm);
    }

    public function testRecognizeHindiNonLatinScript(): void
    {
        $this->provider->addEntry(new TermEntry(
            sourceTerm: 'अनुवाद',
            targetTerm: 'translation',
            sourceLanguage: 'hi',
            targetLanguage: 'en',
        ));

        $matches = $this->provider->recognize('यह अनुवाद सही है', 'hi', 'en');
        $this->assertCount(1, $matches);
        $this->assertSame('अनुवाद', $matches[0]->entry->sourceTerm);
    }

    public function testRecognizeReturnsEmptyWhenNoTermbase(): void
    {
        $matches = $this->provider->recognize('some text', 'en', 'fr');
        $this->assertSame([], $matches);
    }

    // ── import ───────────────────────────────────────────────────────────

    public function testImportFromTbxFile(): void
    {
        $count = $this->provider->import(__DIR__ . '/../fixtures/sample.tbx');

        $this->assertGreaterThan(0, $count);

        $results = $this->provider->lookup('translation memory', 'en', 'fr');
        $this->assertCount(1, $results);
        $this->assertSame('mémoire de traduction', $results[0]->targetTerm);
    }
}
