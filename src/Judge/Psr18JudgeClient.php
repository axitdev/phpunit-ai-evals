<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use JsonException;
use Psr\Http\Client\ClientInterface;
use UnexpectedValueException;

/**
 * Judge speaking the OpenAI chat-completions protocol over any PSR-18 client.
 */
final class Psr18JudgeClient implements JudgeClientInterface
{
    private readonly JudgeHttp $http;

    public function __construct(
        ClientInterface $http,
        private readonly JudgeSettings $settings,
    ) {
        $this->http = new JudgeHttp($http);
    }

    /**
     * @internal public for tests only
     */
    public static function retryDelay(string $retryAfter, int $attempt): int
    {
        return JudgeHttp::retryDelay($retryAfter, $attempt);
    }

    /**
     * Model and messages only: the hash a verdict was saved under before other judges existed, so old cassettes match.
     */
    public function cachePayload(JudgeRequest $request): array
    {
        [$system, $user] = JudgePrompts::forRequest($request);

        return [
            'model' => $this->settings->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];
    }

    public function judge(JudgeRequest $request): JudgeVerdict
    {
        $body = $this->http->postJson(
            rtrim($this->settings->baseUri, '/') . '/chat/completions',
            $this->settings->apiKey,
            $this->cachePayload($request)
            + ($this->settings->temperature !== null ? ['temperature' => $this->settings->temperature] : [])
            + ($this->settings->jsonMode ? ['response_format' => ['type' => 'json_object']] : []),
        );

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
