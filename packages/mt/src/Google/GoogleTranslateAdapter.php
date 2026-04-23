<?php

declare(strict_types=1);

namespace CatFramework\Mt\Google;

use CatFramework\Core\Model\Segment;
use CatFramework\Mt\AbstractMtAdapter;
use CatFramework\Mt\Exception\MtException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Google Cloud Translation API v3 adapter.
 *
 * Tag handling limitation: Google v3 does not reliably preserve XML placeholders.
 * InlineCodes are stripped before sending; the returned Segment contains only
 * plain text. The QA tag_consistency check will flag the missing codes so the
 * translator can re-insert them manually in the editor.
 */
final class GoogleTranslateAdapter extends AbstractMtAdapter
{
    private const string ENDPOINT_TPL = 'https://translation.googleapis.com/v3/projects/%s/locations/global:translateText';

    /** Default exponential backoff delays (µs) for three retry attempts on 429. */
    public const array DEFAULT_RETRY_DELAYS = [1_000_000, 2_000_000, 4_000_000];

    /** Courtesy throttle between sequential batch calls (100 ms). */
    private const int BATCH_THROTTLE_US = 100_000;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        private readonly string $apiKey,
        private readonly string $projectId,
        private readonly array $retryDelays = self::DEFAULT_RETRY_DELAYS,
    ) {
        parent::__construct($httpClient, $requestFactory, $streamFactory);
    }

    /**
     * Translate a single segment. InlineCodes are stripped; the returned Segment
     * contains only the translated plain text.
     */
    public function translate(Segment $source, string $sourceLanguage, string $targetLanguage): Segment
    {
        $plainText = $source->getPlainText();

        $payload = json_encode([
            'sourceLanguageCode' => strtolower($sourceLanguage),
            'targetLanguageCode' => strtolower($targetLanguage),
            'contents'           => [$plainText],
            'mimeType'           => 'text/plain',
        ]);

        $endpoint = sprintf(self::ENDPOINT_TPL, rawurlencode($this->projectId));

        $request = $this->requestFactory
            ->createRequest('POST', $endpoint)
            ->withHeader('X-Goog-Api-Key', $this->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($payload));

        $response = $this->retry(
            fn() => $this->sendRequest($request),
            $this->retryDelays,
            [MtException::RATE_LIMITED],
        );

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || !isset($data['translations'][0]['translatedText'])) {
            throw new MtException('[google_v3] Unexpected response format', MtException::SERVER_ERROR);
        }

        return new Segment($source->id, [$data['translations'][0]['translatedText']]);
    }

    /**
     * Translate multiple segments sequentially.
     * Google v3 does not support multi-segment batching in a single call.
     * A 100 ms courtesy throttle is applied between calls.
     */
    public function translateBatch(array $sources, string $sourceLanguage, string $targetLanguage): array
    {
        $results = [];
        foreach ($sources as $i => $source) {
            if ($i > 0) {
                usleep(self::BATCH_THROTTLE_US);
            }
            $results[] = $this->translate($source, $sourceLanguage, $targetLanguage);
        }
        return $results;
    }

    public function getProviderId(): string
    {
        return 'google_v3';
    }
}
