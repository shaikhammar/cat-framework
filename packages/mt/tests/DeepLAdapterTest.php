<?php

declare(strict_types=1);

namespace CatFramework\Mt\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Mt\DeepL\DeepLAdapter;
use CatFramework\Mt\Exception\MtException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class DeepLAdapterTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private RequestFactoryInterface&MockObject $requestFactory;
    private StreamFactoryInterface&MockObject $streamFactory;
    private RequestInterface&MockObject $request;

    protected function setUp(): void
    {
        $this->httpClient     = $this->createMock(ClientInterface::class);
        $this->requestFactory = $this->createMock(RequestFactoryInterface::class);
        $this->streamFactory  = $this->createMock(StreamFactoryInterface::class);
        $this->request        = $this->createMock(RequestInterface::class);

        // PSR-17 request factory stub: createRequest() → request that accepts header/body fluently
        $this->request->method('withHeader')->willReturnSelf();
        $this->request->method('withBody')->willReturnSelf();
        $this->requestFactory->method('createRequest')->willReturn($this->request);
        $this->streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));
    }

    private function makeAdapter(string $apiKey = 'test-key-123'): DeepLAdapter
    {
        return new DeepLAdapter(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $apiKey,
        );
    }

    private function makeResponse(int $status, string $body): ResponseInterface
    {
        $stream   = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    public function testProviderIdIsDeepL(): void
    {
        $this->assertSame('deepl', $this->makeAdapter()->getProviderId());
    }

    public function testTranslateSingleSegmentPlainText(): void
    {
        $responseJson = json_encode([
            'translations' => [['text' => 'Hallo Welt', 'detected_source_language' => 'EN']],
        ]);

        $this->httpClient->method('sendRequest')->willReturn($this->makeResponse(200, $responseJson));

        $source = new Segment('s1', ['Hello world']);
        $result = $this->makeAdapter()->translate($source, 'en', 'de');

        $this->assertSame('s1', $result->id);
        $this->assertSame('Hallo Welt', $result->getPlainText());
    }

    public function testTranslateBatchReturnsResultsInInputOrder(): void
    {
        $responseJson = json_encode([
            'translations' => [
                ['text' => 'Eins'],
                ['text' => 'Zwei'],
            ],
        ]);

        $this->httpClient->method('sendRequest')->willReturn($this->makeResponse(200, $responseJson));

        $adapter = $this->makeAdapter();
        $sources = [new Segment('s1', ['One']), new Segment('s2', ['Two'])];
        $results = $adapter->translateBatch($sources, 'en', 'de');

        $this->assertCount(2, $results);
        $this->assertSame('s1', $results[0]->id);
        $this->assertSame('Eins', $results[0]->getPlainText());
        $this->assertSame('s2', $results[1]->id);
        $this->assertSame('Zwei', $results[1]->getPlainText());
    }

    public function testTranslateBatchWithEmptyInputReturnsEmptyArray(): void
    {
        $this->httpClient->expects($this->never())->method('sendRequest');

        $results = $this->makeAdapter()->translateBatch([], 'en', 'de');
        $this->assertSame([], $results);
    }

    public function testInlineCodesArePreservedRoundTrip(): void
    {
        $open  = new InlineCode('b1', InlineCodeType::OPENING, '<b>');
        $close = new InlineCode('b1', InlineCodeType::CLOSING, '</b>');

        // DeepL returns the placeholder tags preserved in the response
        $responseJson = json_encode([
            'translations' => [['text' => 'Hallo <x id="1"/>Welt<x id="2"/>!']],
        ]);
        $this->httpClient->method('sendRequest')->willReturn($this->makeResponse(200, $responseJson));

        $source  = new Segment('s1', ['Hello ', $open, 'world', $close, '!']);
        $result  = $this->makeAdapter()->translate($source, 'en', 'de');
        $inlines = $result->getInlineCodes();

        $this->assertCount(2, $inlines);
        $this->assertSame($open, $inlines[0]);
        $this->assertSame($close, $inlines[1]);
        $this->assertSame('Hallo Welt!', $result->getPlainText());
    }

    public function testFreeApiKeyUsesFreeTierEndpoint(): void
    {
        $responseJson = json_encode([
            'translations' => [['text' => 'ok']],
        ]);
        $this->httpClient->method('sendRequest')->willReturn($this->makeResponse(200, $responseJson));

        $this->requestFactory
            ->expects($this->once())
            ->method('createRequest')
            ->with('POST', 'https://api-free.deepl.com/v2/translate')
            ->willReturn($this->request);

        $adapter = new DeepLAdapter(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            'my-key:fx',
        );

        $adapter->translate(new Segment('s1', ['Hello']), 'en', 'de');
    }

    public function testProApiKeyUsesProEndpoint(): void
    {
        $responseJson = json_encode([
            'translations' => [['text' => 'ok']],
        ]);
        $this->httpClient->method('sendRequest')->willReturn($this->makeResponse(200, $responseJson));

        $this->requestFactory
            ->expects($this->once())
            ->method('createRequest')
            ->with('POST', 'https://api.deepl.com/v2/translate')
            ->willReturn($this->request);

        $this->makeAdapter('pro-key-no-fx')
            ->translate(new Segment('s1', ['Hello']), 'en', 'de');
    }

    public function testAuthFailureThrowsMtExceptionWithAuthFailedCode(): void
    {
        $this->httpClient->method('sendRequest')->willReturn($this->makeResponse(403, ''));

        $this->expectException(MtException::class);
        $this->expectExceptionCode(MtException::AUTH_FAILED);

        $this->makeAdapter()->translate(new Segment('s1', ['test']), 'en', 'de');
    }

    public function testQuotaExceededThrowsMtExceptionWithQuotaCode(): void
    {
        $this->httpClient->method('sendRequest')->willReturn($this->makeResponse(456, ''));

        $this->expectException(MtException::class);
        $this->expectExceptionCode(MtException::QUOTA_EXCEEDED);

        $this->makeAdapter()->translate(new Segment('s1', ['test']), 'en', 'de');
    }

    public function testUnsupportedLanguageThrowsLanguageNotSupportedException(): void
    {
        $this->expectException(MtException::class);
        $this->expectExceptionCode(MtException::LANGUAGE_NOT_SUPPORTED);

        // Urdu is not supported by DeepL
        $this->makeAdapter()->translate(new Segment('s1', ['Hello']), 'ur', 'de');
    }

    public function testRateLimitRetriesAndEventuallyThrows(): void
    {
        // 3 delays = 3 retries = 4 total attempts. Zero delays so the test doesn't sleep.
        $this->httpClient
            ->expects($this->exactly(4))
            ->method('sendRequest')
            ->willReturn($this->makeResponse(429, ''));

        $this->expectException(MtException::class);
        $this->expectExceptionCode(MtException::RATE_LIMITED);

        $adapter = new DeepLAdapter(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            'test-key',
            [0, 0, 0],
        );

        $adapter->translate(new Segment('s1', ['Hello']), 'en', 'de');
    }
}
