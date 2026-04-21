<?php

declare(strict_types=1);

namespace CatFramework\Tmx\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Core\Model\TranslationUnit;
use CatFramework\Tmx\TmxException;
use CatFramework\Tmx\TmxReader;
use CatFramework\Tmx\TmxWriter;
use PHPUnit\Framework\TestCase;

class TmxRoundTripTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/tmx_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    // --- basic round-trip ---

    public function test_plain_text_round_trip(): void
    {
        $unit = $this->makeUnit('Hello world.', 'Bonjour le monde.');

        [$result] = $this->writeAndRead([$unit]);

        $this->assertSame('Hello world.',      $result->source->getPlainText());
        $this->assertSame('Bonjour le monde.', $result->target->getPlainText());
        $this->assertSame('en-US',             $result->sourceLanguage);
        $this->assertSame('fr-FR',             $result->targetLanguage);
    }

    public function test_multiple_units_preserve_order(): void
    {
        $units = [
            $this->makeUnit('One.', 'Un.'),
            $this->makeUnit('Two.', 'Deux.'),
            $this->makeUnit('Three.', 'Trois.'),
        ];

        $result = $this->writeAndRead($units);

        $this->assertCount(3, $result);
        $this->assertSame('One.',   $result[0]->source->getPlainText());
        $this->assertSame('Two.',   $result[1]->source->getPlainText());
        $this->assertSame('Three.', $result[2]->source->getPlainText());
    }

    // --- metadata ---

    public function test_created_by_round_trips(): void
    {
        $unit = $this->makeUnit('Hello.', 'Bonjour.', createdBy: 'translator@example.com');

        [$result] = $this->writeAndRead([$unit]);

        $this->assertSame('translator@example.com', $result->createdBy);
    }

    public function test_creation_date_round_trips(): void
    {
        $dt   = new \DateTimeImmutable('2024-06-15 10:30:00', new \DateTimeZone('UTC'));
        $unit = $this->makeUnit('Hello.', 'Bonjour.', createdAt: $dt);

        [$result] = $this->writeAndRead([$unit]);

        $this->assertSame(
            $dt->format('Ymd\THis\Z'),
            $result->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
        );
    }

    public function test_last_used_at_round_trips(): void
    {
        $dt   = new \DateTimeImmutable('2024-11-01 08:00:00', new \DateTimeZone('UTC'));
        $unit = $this->makeUnit('Hello.', 'Bonjour.', lastUsedAt: $dt);

        [$result] = $this->writeAndRead([$unit]);

        $this->assertNotNull($result->lastUsedAt);
        $this->assertSame(
            $dt->format('Ymd\THis\Z'),
            $result->lastUsedAt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
        );
    }

    public function test_null_last_used_at_stays_null(): void
    {
        $unit = $this->makeUnit('Hello.', 'Bonjour.');

        [$result] = $this->writeAndRead([$unit]);

        $this->assertNull($result->lastUsedAt);
    }

    public function test_prop_metadata_round_trips(): void
    {
        $unit = $this->makeUnit('Hello.', 'Bonjour.', metadata: [
            'x-project' => 'MyProject',
            'x-domain'  => 'legal',
        ]);

        [$result] = $this->writeAndRead([$unit]);

        $this->assertSame('MyProject', $result->metadata['x-project']);
        $this->assertSame('legal',     $result->metadata['x-domain']);
    }

    public function test_notes_round_trip(): void
    {
        $unit = $this->makeUnit('Hello.', 'Bonjour.', metadata: [
            'notes' => ['First note', 'Second note'],
        ]);

        [$result] = $this->writeAndRead([$unit]);

        $this->assertSame(['First note', 'Second note'], $result->metadata['notes']);
    }

    // --- inline codes ---

    public function test_opening_and_closing_codes_round_trip(): void
    {
        $elements = [
            'Hello ',
            new InlineCode('1', InlineCodeType::OPENING,  '<b>'),
            'world',
            new InlineCode('1', InlineCodeType::CLOSING,  '</b>'),
            '.',
        ];
        $unit = $this->makeUnitWithElements($elements, ['Bonjour le monde.']);

        [$result] = $this->writeAndRead([$unit]);
        $codes = $result->source->getInlineCodes();

        $this->assertCount(2, $codes);
        $this->assertSame(InlineCodeType::OPENING, $codes[0]->type);
        $this->assertSame('<b>',                   $codes[0]->data);
        $this->assertFalse($codes[0]->isIsolated);
        $this->assertSame(InlineCodeType::CLOSING, $codes[1]->type);
        $this->assertSame('</b>',                  $codes[1]->data);
        $this->assertFalse($codes[1]->isIsolated);
    }

    public function test_standalone_code_round_trips(): void
    {
        $elements = [
            'Line one.',
            new InlineCode('1', InlineCodeType::STANDALONE, '<br/>'),
            'Line two.',
        ];
        $unit = $this->makeUnitWithElements($elements, ['Ligne une.']);

        [$result] = $this->writeAndRead([$unit]);
        $codes = $result->source->getInlineCodes();

        $this->assertCount(1, $codes);
        $this->assertSame(InlineCodeType::STANDALONE, $codes[0]->type);
        $this->assertSame('<br/>',                     $codes[0]->data);
        $this->assertFalse($codes[0]->isIsolated);
    }

    public function test_isolated_codes_round_trip(): void
    {
        $elements = [
            new InlineCode('1', InlineCodeType::OPENING, '<b>', null, true),
            'Bold text.',
            new InlineCode('1', InlineCodeType::CLOSING, '</b>', null, true),
        ];
        $unit = $this->makeUnitWithElements($elements, ['Texte en gras.']);

        [$result] = $this->writeAndRead([$unit]);
        $codes = $result->source->getInlineCodes();

        $this->assertCount(2, $codes);
        $this->assertTrue($codes[0]->isIsolated);
        $this->assertSame(InlineCodeType::OPENING, $codes[0]->type);
        $this->assertTrue($codes[1]->isIsolated);
        $this->assertSame(InlineCodeType::CLOSING, $codes[1]->type);
    }

    // --- encoding ---

    public function test_urdu_rtl_text_round_trips(): void
    {
        $urdu   = 'یہ پہلا جملہ ہے۔';
        $hindi  = 'यह पहला वाक्य है।';
        $unit   = $this->makeUnit($urdu, $hindi, sourceLang: 'ur-PK', targetLang: 'hi-IN');

        [$result] = $this->writeAndRead([$unit]);

        $this->assertSame($urdu,  $result->source->getPlainText());
        $this->assertSame($hindi, $result->target->getPlainText());
    }

    public function test_xml_special_chars_round_trip(): void
    {
        $text = 'Use <b> & "quotes" and \'apostrophes\'.';
        $unit = $this->makeUnit($text, $text);

        [$result] = $this->writeAndRead([$unit]);

        $this->assertSame($text, $result->source->getPlainText());
        $this->assertSame($text, $result->target->getPlainText());
    }

    // --- streaming mode ---

    public function test_streaming_yields_same_units_as_dom(): void
    {
        $units = [
            $this->makeUnit('First sentence.', 'Première phrase.'),
            $this->makeUnit('Second sentence.', 'Deuxième phrase.'),
        ];

        $path     = $this->writeTmx($units);
        $dom      = (new TmxReader())->read($path);
        $streamed = iterator_to_array((new TmxReader())->stream($path));

        $this->assertCount(count($dom), $streamed);

        foreach ($dom as $i => $unit) {
            $this->assertSame(
                $unit->source->getPlainText(),
                $streamed[$i]->source->getPlainText(),
            );
            $this->assertSame(
                $unit->target->getPlainText(),
                $streamed[$i]->target->getPlainText(),
            );
        }
    }

    public function test_streaming_large_file_yields_all_units(): void
    {
        $units = [];
        for ($i = 1; $i <= 200; $i++) {
            $units[] = $this->makeUnit("Source sentence {$i}.", "Target sentence {$i}.");
        }

        $path     = $this->writeTmx($units);
        $streamed = iterator_to_array((new TmxReader())->stream($path));

        $this->assertCount(200, $streamed);
        $this->assertSame('Source sentence 1.',   $streamed[0]->source->getPlainText());
        $this->assertSame('Source sentence 200.', $streamed[199]->source->getPlainText());
    }

    public function test_streaming_preserves_inline_codes(): void
    {
        $elements = [
            new InlineCode('1', InlineCodeType::OPENING,  '<b>'),
            'Bold text.',
            new InlineCode('1', InlineCodeType::CLOSING,  '</b>'),
        ];
        $unit = $this->makeUnitWithElements($elements, ['Texte en gras.']);

        $path     = $this->writeTmx([$unit]);
        $streamed = iterator_to_array((new TmxReader())->stream($path));

        $codes = $streamed[0]->source->getInlineCodes();
        $this->assertCount(2, $codes);
        $this->assertSame(InlineCodeType::OPENING, $codes[0]->type);
    }

    // --- validation ---

    public function test_invalid_file_throws(): void
    {
        $path = $this->tmpDir . '/bad.tmx';
        file_put_contents($path, 'this is not xml');

        $this->expectException(TmxException::class);
        (new TmxReader())->read($path);
    }

    public function test_missing_header_throws(): void
    {
        $path = $this->tmpDir . '/noheader.tmx';
        file_put_contents($path, '<?xml version="1.0"?><tmx version="1.4"><body></body></tmx>');

        $this->expectException(TmxException::class);
        (new TmxReader())->read($path);
    }

    public function test_nonexistent_file_throws_on_stream(): void
    {
        $this->expectException(TmxException::class);
        iterator_to_array((new TmxReader())->stream('/nonexistent/path.tmx'));
    }

    // --- helpers ---

    private function makeUnit(
        string $sourceText,
        string $targetText,
        string $sourceLang  = 'en-US',
        string $targetLang  = 'fr-FR',
        ?string $createdBy  = null,
        ?\DateTimeImmutable $createdAt   = null,
        ?\DateTimeImmutable $lastUsedAt  = null,
        array $metadata     = [],
    ): TranslationUnit {
        return new TranslationUnit(
            source:         new Segment('src-' . uniqid(), [$sourceText]),
            target:         new Segment('tgt-' . uniqid(), [$targetText]),
            sourceLanguage: $sourceLang,
            targetLanguage: $targetLang,
            createdAt:      $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            lastUsedAt:     $lastUsedAt,
            createdBy:      $createdBy,
            metadata:       $metadata,
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

    /** @param TranslationUnit[] $units */
    private function writeTmx(array $units): string
    {
        $path = $this->tmpDir . '/test_' . uniqid() . '.tmx';
        (new TmxWriter())->write($units, $path);
        return $path;
    }

    /**
     * @param TranslationUnit[] $units
     * @return TranslationUnit[]
     */
    private function writeAndRead(array $units): array
    {
        return (new TmxReader())->read($this->writeTmx($units));
    }
}
