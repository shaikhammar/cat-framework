<?php

declare(strict_types=1);

namespace CatFramework\Mt\DeepL;

use CatFramework\Core\Model\Segment;
use CatFramework\Mt\AbstractMtAdapter;
use CatFramework\Mt\Exception\MtException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class DeepLAdapter extends AbstractMtAdapter
{
    private const string ENDPOINT_PRO  = 'https://api.deepl.com/v2/translate';
    private const string ENDPOINT_FREE = 'https://api-free.deepl.com/v2/translate';

    /** Default microsecond delays for three retry attempts on 429 / 5xx. */
    public const array DEFAULT_RETRY_DELAYS = [1_000_000, 1_000_000, 1_000_000];

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        private readonly string $apiKey,
        private readonly array $retryDelays = self::DEFAULT_RETRY_DELAYS,
    ) {
        parent::__construct($httpClient, $requestFactory, $streamFactory);
    }

    public function translate(Segment $source, string $sourceLanguage, string $targetLanguage): Segment
    {
        return $this->translateBatch([$source], $sourceLanguage, $targetLanguage)[0];
    }

    public function translateBatch(array $sources, string $sourceLanguage, string $targetLanguage): array
    {
        if ($sources === []) {
            return [];
        }

        $sourceLang = DeepLLanguageMapper::toSourceLang($sourceLanguage);
        $targetLang = DeepLLanguageMapper::toTargetLang($targetLanguage);

        $encodedTexts = [];
        $maps         = [];

        foreach ($sources as $segment) {
            ['text' => $text, 'map' => $map] = $this->encodeSegment($segment);
            $encodedTexts[] = $text;
            $maps[]         = $map;
        }

        $body = $this->buildBody($encodedTexts, $sourceLang, $targetLang);

        $request = $this->requestFactory
            ->createRequest('POST', $this->endpoint())
            ->withHeader('Authorization', 'DeepL-Auth-Key ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream($body));

        $response = $this->retry(
            fn() => $this->sendRequest($request),
            $this->retryDelays,
            [MtException::RATE_LIMITED, MtException::SERVER_ERROR],
        );

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['translations'])) {
            throw new MtException('[deepl] Unexpected response format', MtException::SERVER_ERROR);
        }

        $results = [];
        foreach ($data['translations'] as $i => $translation) {
            $translatedText = $translation['text'] ?? '';
            $results[]      = $this->decodeXml($translatedText, $maps[$i], $sources[$i]->id);
        }

        return $results;
    }

    public function getProviderId(): string
    {
        return 'deepl';
    }

    private function endpoint(): string
    {
        return str_ends_with($this->apiKey, ':fx') ? self::ENDPOINT_FREE : self::ENDPOINT_PRO;
    }

    private function buildBody(array $texts, string $sourceLang, string $targetLang): string
    {
        // Build text[] parameters manually to avoid numeric indices from http_build_query
        $parts = [];
        foreach ($texts as $text) {
            $parts[] = 'text[]=' . rawurlencode($text);
        }
        $parts[] = 'source_lang=' . rawurlencode($sourceLang);
        $parts[] = 'target_lang=' . rawurlencode($targetLang);
        $parts[] = 'tag_handling=xml';
        $parts[] = 'ignore_tags=x';

        return implode('&', $parts);
    }
}
