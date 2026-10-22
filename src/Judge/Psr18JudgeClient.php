<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Http\Discovery\Psr17FactoryDiscovery;
use JsonException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Judge speaking the OpenAI chat-completions protocol over any PSR-18 client.
 */
final class Psr18JudgeClient implements JudgeClientInterface
{
    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private const MAX_ATTEMPTS = 3;

    private const MAX_RETRY_AFTER_SECONDS = 30;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly JudgeSettings $settings,
    ) {
        $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = Psr17FactoryDiscovery::findStreamFactory();
    }

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

    public function judge(string $systemPrompt, string $userPrompt): JudgeVerdict
    {
        $request = $this->requestFactory->createRequest('POST', rtrim($this->settings->baseUri, '/') . '/chat/completions')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->settings->apiKey)
            ->withBody($this->streamFactory->createStream(json_encode(
                [
                    'model' => $this->settings->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ]
                + ($this->settings->temperature !== null ? ['temperature' => $this->settings->temperature] : [])
                + ($this->settings->jsonMode ? ['response_format' => ['type' => 'json_object']] : []),
                JSON_THROW_ON_ERROR,
            )));

        for ($attempt = 1; ; $attempt++) {
            $response = $this->http->sendRequest($request);
            $status = $response->getStatusCode();
            if (($status === 429 || $status >= 500) && $attempt < self::MAX_ATTEMPTS) {
                sleep(self::retryDelay($response->getHeaderLine('Retry-After'), $attempt));
                continue;
            }
            break;
        }

        $body = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Judge request failed with HTTP %d: %s', $status, mb_substr($body, 0, 300)));
        }

        $answer = Payload::string(Payload::get(json_decode($body, true), 'choices', 0, 'message', 'content'));
        $data = self::extractJson($answer);
        $score = is_numeric($data['score'] ?? null) ? (float) $data['score'] : null;
        // Never clamp: a 7 on a 1-10 scale would pass as 1.0.
        if ($score === null || $score < 0.0 || $score > 1.0) {
            throw new UnexpectedValueException('Judge answer has no score between 0.0 and 1.0: ' . mb_substr($answer, 0, 200));
        }

        return new JudgeVerdict($score, Payload::string($data['reason'] ?? null));
    }

    /**
     * The last balanced `{...}` that decodes and has a `score`, so fences, prose, notes and `<think>` blocks around the
     * answer don't matter. Braces and quotes inside JSON strings are skipped.
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    private static function extractJson(string $text): array
    {
        preg_match_all('/\{(?:[^{}"]++|"(?:[^"\\\\]++|\\\\.)*+"|(?R))*+\}/s', $text, $matches);
        $objects = array_values(array_filter(array_map(static fn(string $json): mixed => json_decode($json, true), $matches[0]), is_array(...)));
        if ($objects === []) {
            throw new JsonException('No JSON object found in judge output: ' . mb_substr($text, 0, 200));
        }
        foreach (array_reverse($objects) as $object) {
            if (array_key_exists('score', $object)) {
                return Payload::map($object);
            }
        }

        return Payload::map($objects[0]);
    }
}
