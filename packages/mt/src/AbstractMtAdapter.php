<?php

declare(strict_types=1);

namespace CatFramework\Mt;

use CatFramework\Core\Contract\MachineTranslationInterface;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;
use CatFramework\Mt\Exception\MtException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

abstract class AbstractMtAdapter implements MachineTranslationInterface
{
    public function __construct(
        protected readonly ClientInterface $httpClient,
        protected readonly RequestFactoryInterface $requestFactory,
        protected readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Convert a Segment's InlineCodes to numbered XML placeholders.
     *
     * Each InlineCode becomes <x id="N"/> (N = sequential 1-based integer).
     * Text content is XML-escaped so source text with bare & < > remains valid.
     *
     * @return array{text: string, map: array<int, InlineCode>}
     */
    protected function encodeSegment(Segment $segment): array
    {
        $text    = '';
        $map     = [];
        $counter = 0;

        foreach ($segment->getElements() as $element) {
            if (is_string($element)) {
                $text .= htmlspecialchars($element, ENT_XML1, 'UTF-8');
            } else {
                /** @var InlineCode $element */
                $counter++;
                $map[$counter] = $element;
                $text .= '<x id="' . $counter . '"/>';
            }
        }

        return ['text' => $text, 'map' => $map];
    }

    /**
     * Parse an MT response string back into a Segment, restoring InlineCodes.
     *
     * The response is wrapped in <seg>…</seg> to form a valid XML document
     * before parsing. On XML parse failure the method falls back to stripping
     * all <x …/> tags and returning a plain-text Segment; this is preferable
     * to throwing, since degraded output is better than a crash.
     *
     * @param array<int, InlineCode> $map  Placeholder ID → original InlineCode
     */
    protected function decodeXml(string $xmlResponse, array $map, string $segmentId): Segment
    {
        $wrapped = '<seg>' . $xmlResponse . '</seg>';

        $dom = new \DOMDocument('1.0', 'UTF-8');
        if (!@$dom->loadXML($wrapped, LIBXML_NONET)) {
            // Malformed MT response — strip placeholders, return plain text
            $plainText = preg_replace('/<x[^>]*\/?>/i', '', $xmlResponse) ?? $xmlResponse;
            return new Segment($segmentId, [$plainText]);
        }

        $elements = [];
        /** @var \DOMElement $root */
        $root = $dom->documentElement;

        foreach ($root->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                if ($node->nodeValue !== null && $node->nodeValue !== '') {
                    $elements[] = $node->nodeValue;
                }
            } elseif ($node->nodeType === XML_ELEMENT_NODE && $node->nodeName === 'x') {
                /** @var \DOMElement $node */
                $placeholderId = (int) $node->getAttribute('id');
                if (isset($map[$placeholderId])) {
                    $elements[] = $map[$placeholderId];
                } else {
                    // Unknown placeholder returned by MT — treat as text
                    $elements[] = '{' . $placeholderId . '}';
                }
            }
        }

        return new Segment($segmentId, $elements);
    }

    /**
     * Send a PSR-7 request, returning the response on 2xx.
     * Throws MtException for 4xx/5xx and connection-level failures.
     * Does NOT retry — concrete adapters wrap this in retry() where needed.
     */
    protected function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new MtException(
                'HTTP client error: ' . $e->getMessage(),
                MtException::SERVER_ERROR,
                $e,
            );
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return $response;
        }

        $this->throwForStatus($status, $this->getProviderId());
    }

    /**
     * Retry a callable on MtException, sleeping between attempts.
     *
     * @param callable(): mixed   $fn            The operation to retry.
     * @param int[]               $delaySleepUs  Microseconds to sleep before each
     *                                           retry attempt. Count = max retries.
     * @param int[]               $retryOnCodes  MtException codes that trigger a
     *                                           retry. Empty = retry on any MtException.
     * @throws MtException When all attempts are exhausted.
     */
    protected function retry(callable $fn, array $delaySleepUs, array $retryOnCodes = []): mixed
    {
        $lastException = null;
        $maxAttempts   = count($delaySleepUs) + 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $fn();
            } catch (MtException $e) {
                if ($retryOnCodes !== [] && !in_array($e->getCode(), $retryOnCodes, true)) {
                    throw $e;
                }
                $lastException = $e;
                if ($attempt < $maxAttempts) {
                    usleep($delaySleepUs[$attempt - 1]);
                }
            }
        }

        throw $lastException;
    }

    /**
     * Map an HTTP status code to a typed MtException and throw it.
     */
    protected function throwForStatus(int $statusCode, string $providerId): never
    {
        $message = sprintf('[%s] HTTP %d', $providerId, $statusCode);

        $code = match (true) {
            $statusCode === 403           => MtException::AUTH_FAILED,
            $statusCode === 429           => MtException::RATE_LIMITED,
            $statusCode === 456           => MtException::QUOTA_EXCEEDED,
            $statusCode >= 400 && $statusCode < 500 => MtException::BAD_REQUEST,
            default                       => MtException::SERVER_ERROR,
        };

        throw new MtException($message, $code);
    }
}
