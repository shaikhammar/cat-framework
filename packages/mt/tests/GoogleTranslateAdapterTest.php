<?php

declare(strict_types=1);

namespace CatFramework\Mt\Tests;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Mt\Exception\MtException;
use CatFramework\Mt\Google\GoogleTranslateAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class GoogleTranslateAdapterTest extends TestCase
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

        $this->request->method('withHeader')->willReturnSelf();
        $this->request->method('withBody')->willReturnSelf();
        $this->requestFactory->method('createRequest')->willReturn($this->request);
        $this->streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));
    }

    private function makeAdapter(string $apiKey = 'test-key', string $projectId = 'my-project'): GoogleTranslateAdapter
    {
        return new GoogleTranslateAdapter(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $apiKey,
            $projectId,
        );
    }

    private function makeResponse(int $status, string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    public function testProviderIdIsGoogleV3(): void
    {
        $this->assertSame('google_v3', $this->makeAdapter()->getProviderId());
    }

    public function testTranslatePlainTextSegment(): void
    {
        $responseJson = json_encode([
            'translations' => [['translatedText' => 'Hallo Welt']],
        ]);

        $this->httpClient->method('sendRequest')->willReturn($this->makeResponse(200, $responseJson));

        $source = new Segment('s1', ['Hello world']);
        $result = $this->makeAdapter()->translate($source, 'en', 'de');

        $this->assertSame('s1', $result->id);
        $this->assertSame('Hallo Welt', $result->getPlainText());
    }

    public function testInlineCodesAreStrippedBeforeSending(): void
    {
        $responseJson = json_encode([
            'translations' => [['translatedText' => 'Hallo Welt']],
        ]);

        $this->httpClient->method('sendRequest')->willReturn($this->makeResponse(200, $responseJson));

        // Source has inline codes — they must NOT appear in the result
        $open    = new InlineCode('b1', InlineCodeType::OPENING, '<b>');
        $close   = new InlineCode('b1', InlineCodeType::CLOSING, '</b>');
        $source  = new Segment('s1', ['Hello ', $open, 'world', $close]);

        $result = $this->makeAdapter()->translate($source, 'en', 'de');

        $this->assertSame([], $result->getInlineCodes());
        $this->assertSame('Hallo Welt', $result->getPlainText());
    }

    public function testTranslateBatchCallsTranslateOncePerSegment(): void
    {
        $responseJson = json_encode([
            'translations' => [['translatedText' => 'ok']],
        ]);

        // translateBatch loops over translate() — expect one HTTP call per segment
        $this->httpClient
            ->expects($this->exactly(3))
            ->method('sendRequest')
            ->willReturn($this->makeResponse(200, $responseJson));

        $adapter = $this->makeAdapter();
        $sources = [
            new Segment('s1', ['One']),
            new Segment('s2', ['Two']),
            new Segment('s3', ['Three']),
        ];

        $results = $adapter->translateBatch($sources, 'en', 'de');

        $this->assertCount(3, $results);
        $this->assertSame('s1', $results[0]->id);
        $this->assertSame('s2', $results[1]->id);
        $this->assertSame('s3', $results[2]->id);
    }

    public function testEndpointContainsProjectId(): void
    {
        $responseJson = json_encode([
            'translations' => [['translatedText' => 'ok']],
        ]);

        $this->httpClient->method('sendRequest')->willReturn($this->makeResponse(200, $responseJson));

        $this->requestFactory
            ->expects($this->once())
            ->method('createRequest')
            ->with('POST', $this->stringContains('my-special-project'))
            ->willReturn($this->request);

        $this->makeAdapter('key', 'my-special-project')
            ->translate(new Segment('s1', ['Hello']), 'en', 'de');
    }

    public function testLanguageCodesAreLowercasedInRequest(): void
    {
        $responseJson = json_encode([
            'translations' => [['translatedText' => 'ok']],
        ]);

        $this->httpClient->method('sendRequest')->willReturn($this->makeResponse(200, $responseJson));

        $capturedBody = null;
        $stream = $this->createMock(StreamInterface::class);
        $this->streamFactory
            ->method('createStream')
            ->willReturnCallback(function (string $content) use (&$capturedBody, $stream) {
                $capturedBody = $content;
                return $stream;
            });

        $this->makeAdapter()->translate(new Segment('s1', ['Hello']), 'EN-US', 'DE');

        $payload = json_decode($capturedBody, true);
        $this->assertSame('en-us', $payload['sourceLanguageCode']);
        $this->assertSame('de', $payload['targetLanguageCode']);
    }

    public function testAuthFailureThrowsMtException(): void
    {
        $this->httpClient->method('sendRequest')->willReturn($this->makeResponse(403, ''));

        $this->expectException(MtException::class);
        $this->expectExceptionCode(MtException::AUTH_FAILED);

        $this->makeAdapter()->translate(new Segment('s1', ['test']), 'en', 'de');
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

        $adapter = new GoogleTranslateAdapter(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            'test-key',
            'my-project',
            [0, 0, 0],
        );

        $adapter->translate(new Segment('s1', ['test']), 'en', 'de');
    }

    public function testUnexpectedResponseFormatThrowsServerError(): void
    {
        $this->httpClient->method('sendRequest')
            ->willReturn($this->makeResponse(200, '{"unexpected": true}'));

        $this->expectException(MtException::class);
        $this->expectExceptionCode(MtException::SERVER_ERROR);

        $this->makeAdapter()->translate(new Segment('s1', ['test']), 'en', 'de');
    }
}
