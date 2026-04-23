<?php

declare(strict_types=1);

namespace CatFramework\Mt\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Mt\AbstractMtAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Tests for the InlineCode ↔ XML placeholder conversion logic in AbstractMtAdapter.
 * Uses a minimal test double to expose the protected methods.
 */
final class InlinePlaceholderTest extends TestCase
{
    private AbstractMtAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new class(
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class),
        ) extends AbstractMtAdapter {
            public function translate(Segment $source, string $sl, string $tl): Segment
            {
                return $source;
            }

            public function translateBatch(array $sources, string $sl, string $tl): array
            {
                return $sources;
            }

            public function getProviderId(): string
            {
                return 'test';
            }

            public function encode(Segment $s): array
            {
                return $this->encodeSegment($s);
            }

            public function decode(string $xml, array $map, string $id): Segment
            {
                return $this->decodeXml($xml, $map, $id);
            }
        };
    }

    public function testEncodeSegmentWithNoInlineCodes(): void
    {
        $segment = new Segment('s1', ['Hello world']);
        ['text' => $text, 'map' => $map] = $this->adapter->encode($segment);

        $this->assertSame('Hello world', $text);
        $this->assertSame([], $map);
    }

    public function testEncodeSegmentEscapesXmlChars(): void
    {
        // In XML text nodes, & and < must be escaped; quotes do not need to be.
        $segment = new Segment('s1', ['5 < 10 & "yes"']);
        ['text' => $text] = $this->adapter->encode($segment);

        $this->assertSame('5 &lt; 10 &amp; "yes"', $text);
    }

    public function testEncodeSegmentWithOneCodePair(): void
    {
        $open  = new InlineCode('b1', InlineCodeType::OPENING, '<b>');
        $close = new InlineCode('b1', InlineCodeType::CLOSING, '</b>');
        $segment = new Segment('s1', ['Hello ', $open, 'world', $close, '!']);

        ['text' => $text, 'map' => $map] = $this->adapter->encode($segment);

        $this->assertSame('Hello <x id="1"/>world<x id="2"/>!', $text);
        $this->assertSame($open, $map[1]);
        $this->assertSame($close, $map[2]);
    }

    public function testEncodeSegmentWithMultipleCodePairs(): void
    {
        $b1 = new InlineCode('b1', InlineCodeType::OPENING, '<b>');
        $b2 = new InlineCode('b1', InlineCodeType::CLOSING, '</b>');
        $i1 = new InlineCode('i1', InlineCodeType::OPENING, '<i>');
        $i2 = new InlineCode('i1', InlineCodeType::CLOSING, '</i>');

        $segment = new Segment('s1', [$b1, 'Bold', $b2, ' and ', $i1, 'italic', $i2]);
        ['text' => $text, 'map' => $map] = $this->adapter->encode($segment);

        $this->assertSame('<x id="1"/>Bold<x id="2"/> and <x id="3"/>italic<x id="4"/>', $text);
        $this->assertCount(4, $map);
    }

    public function testEncodeSegmentCodeAtStart(): void
    {
        $code    = new InlineCode('br', InlineCodeType::STANDALONE, '<br/>');
        $segment = new Segment('s1', [$code, 'After break']);

        ['text' => $text] = $this->adapter->encode($segment);
        $this->assertStringStartsWith('<x id="1"/>', $text);
    }

    public function testEncodeSegmentCodeAtEnd(): void
    {
        $code    = new InlineCode('br', InlineCodeType::STANDALONE, '<br/>');
        $segment = new Segment('s1', ['Before break', $code]);

        ['text' => $text] = $this->adapter->encode($segment);
        $this->assertStringEndsWith('<x id="1"/>', $text);
    }

    public function testDecodeXmlReconstructsSegment(): void
    {
        $open  = new InlineCode('b1', InlineCodeType::OPENING, '<b>');
        $close = new InlineCode('b1', InlineCodeType::CLOSING, '</b>');
        $map   = [1 => $open, 2 => $close];

        $result = $this->adapter->decode('Hallo <x id="1"/>Welt<x id="2"/>!', $map, 's1');

        $elements = $result->getElements();
        $this->assertSame('s1', $result->id);
        $this->assertCount(5, $elements);
        $this->assertSame('Hallo ', $elements[0]);
        $this->assertSame($open, $elements[1]);
        $this->assertSame('Welt', $elements[2]);
        $this->assertSame($close, $elements[3]);
        $this->assertSame('!', $elements[4]);
    }

    public function testDecodeXmlFallsBackToPlainTextOnMalformedResponse(): void
    {
        // Malformed XML — unclosed tag
        $result = $this->adapter->decode('<not valid xml', [], 's1');

        $elements = $result->getElements();
        $this->assertCount(1, $elements);
        $this->assertIsString($elements[0]);
    }

    public function testDecodeXmlWithEmptyMap(): void
    {
        $result = $this->adapter->decode('Plain translated text', [], 's1');

        $this->assertSame('Plain translated text', $result->getPlainText());
        $this->assertSame([], $result->getInlineCodes());
    }

    public function testDecodeXmlUnknownPlaceholderBecomesText(): void
    {
        // MT returned an <x id="99"/> that was not in the original segment
        $result = $this->adapter->decode('Text <x id="99"/> here', [], 's1');

        $this->assertStringContainsString('{99}', $result->getPlainText());
    }
}
