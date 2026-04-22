<?php

declare(strict_types=1);

namespace CatFramework\Terminology\Tests\Parser;

use CatFramework\Core\Exception\TerminologyException;
use CatFramework\Terminology\Parser\TbxParser;
use PHPUnit\Framework\TestCase;

class TbxParserTest extends TestCase
{
    private TbxParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TbxParser();
    }

    public function testParsesStandardEntry(): void
    {
        $entries = $this->parser->parseFile(__DIR__ . '/../fixtures/sample.tbx');

        // Find the en→fr entry for "translation memory"
        $enFr = array_filter(
            $entries,
            fn ($e) => $e->sourceLanguage === 'en'
                && $e->targetLanguage === 'fr'
                && $e->sourceTerm === 'translation memory'
        );

        $this->assertCount(1, $enFr);

        $entry = array_values($enFr)[0];
        $this->assertSame('mémoire de traduction', $entry->targetTerm);
        $this->assertSame('A database of previously translated segments.', $entry->definition);
        $this->assertSame('localization', $entry->domain);
        $this->assertFalse($entry->forbidden);
    }

    public function testForbiddenTermIsMarked(): void
    {
        $entries = $this->parser->parseFile(__DIR__ . '/../fixtures/sample.tbx');

        $forbidden = array_filter(
            $entries,
            fn ($e) => $e->sourceTerm === 'TM' && $e->sourceLanguage === 'en'
        );

        $this->assertNotEmpty($forbidden);
        $entry = array_values($forbidden)[0];
        $this->assertTrue($entry->forbidden);
    }

    public function testHindiEntryParsed(): void
    {
        $entries = $this->parser->parseFile(__DIR__ . '/../fixtures/sample.tbx');

        $hiEn = array_filter(
            $entries,
            fn ($e) => $e->sourceLanguage === 'hi' && $e->targetLanguage === 'en'
        );

        $this->assertNotEmpty($hiEn);
        $entry = array_values($hiEn)[0];
        $this->assertSame('अनुवाद स्मृति', $entry->sourceTerm);
        $this->assertSame('translation memory', $entry->targetTerm);
    }

    public function testBidirectionalEntriesGenerated(): void
    {
        $entries = $this->parser->parseFile(__DIR__ . '/../fixtures/sample.tbx');

        $enFr = array_filter($entries, fn ($e) => $e->sourceLanguage === 'en' && $e->targetLanguage === 'fr');
        $frEn = array_filter($entries, fn ($e) => $e->sourceLanguage === 'fr' && $e->targetLanguage === 'en');

        $this->assertNotEmpty($enFr);
        $this->assertNotEmpty($frEn);
    }

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(TerminologyException::class);
        $this->parser->parseFile('/nonexistent/file.tbx');
    }

    public function testThrowsOnInvalidXml(): void
    {
        $this->expectException(TerminologyException::class);
        $this->parser->parseString('<not valid xml><<<');
    }

    public function testEmptyBodyReturnsEmptyArray(): void
    {
        $xml = '<?xml version="1.0"?><martif type="TBX"><text><body></body></text></martif>';
        $this->assertSame([], $this->parser->parseString($xml));
    }
}
