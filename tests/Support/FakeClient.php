<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class FakeClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $queue;

    public function __construct(ResponseInterface ...$responses)
    {
        $this->queue = array_values($responses);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->queue) ?? throw new RuntimeException('FakeClient: no queued response (unexpected live call).');
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function request(string $uri, array $payload, string $method = 'POST'): \Nyholm\Psr7\Request
    {
        return new \Nyholm\Psr7\Request(
            $method,
            $uri,
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer sk-secret'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<array{string, array<string, mixed>}> $toolCalls name + arguments
     * @return array<string, mixed>
     */
    public static function openAiReply(string $content, array $toolCalls = [], int $prompt = 10, int $completion = 5): array
    {
        return [
            'model' => 'gpt-6-luna',
            'choices' => [['message' => [
                'content' => $content,
                'tool_calls' => array_map(static fn(array $c): array => ['function' => ['name' => $c[0], 'arguments' => json_encode($c[1])]], $toolCalls),
            ]]],
            'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion],
        ];
    }
}
