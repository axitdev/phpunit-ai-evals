<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * JSON POSTs for judge clients, retried on rate limits and server errors. PSR-17 factories are looked up on the first
 * request, so a judge whose verdicts all come from the cassette never needs them.
 *
 * @internal
 */
final class JudgeHttp
{
    private const int MAX_ATTEMPTS = 3;

    private const int MAX_RETRY_AFTER_SECONDS = 30;

    public function __construct(private readonly ClientInterface $http) {}

    /**
     * Retry-After is either seconds or an HTTP date; without it, back off 1s, then 2s.
     *
     * @internal public for tests only
     */
    public static function retryDelay(string $retryAfter, int $attempt): int
    {
        $at = is_numeric($retryAfter) ? null : strtotime($retryAfter);
        $seconds = match (true) {
            is_numeric($retryAfter) => (int) $retryAfter,
            $at !== null && $at !== false => $at - time(),
            default => $attempt,
        };

        return max(0, min($seconds, self::MAX_RETRY_AFTER_SECONDS));
    }

    /**
     * @param array<string, mixed> $body
     * @return string the response body of the first 2xx answer
     */
    public function postJson(string $url, string $apiKey, array $body): string
    {
        $request = Psr17FactoryDiscovery::findRequestFactory()->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $apiKey)
            ->withBody(Psr17FactoryDiscovery::findStreamFactory()->createStream(json_encode($body, JSON_THROW_ON_ERROR)));

        for ($attempt = 1; ; $attempt++) {
            $response = $this->http->sendRequest($request);
            $status = $response->getStatusCode();
            if (($status === 429 || $status >= 500) && $attempt < self::MAX_ATTEMPTS) {
                sleep(self::retryDelay($response->getHeaderLine('Retry-After'), $attempt));
                continue;
            }
            break;
        }

        $text = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Judge request failed with HTTP %d: %s', $status, mb_substr($text, 0, 300)));
        }

        return $text;
    }
}
