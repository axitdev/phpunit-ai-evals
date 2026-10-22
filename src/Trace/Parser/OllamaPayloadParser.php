<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace\Parser;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\ToolCall;

final class OllamaPayloadParser implements ProviderPayloadParserInterface
{
    public function name(): string
    {
        return 'ollama';
    }

    public function supports(string $uri): bool
    {
        return (bool) preg_match('#/api/(chat|generate)$#', (string) parse_url($uri, PHP_URL_PATH));
    }

    public function requestPayload(string $uri, array $payload): array
    {
        return $payload;
    }

    public function parse(array $request, array $response, float $latencyMs): AiInteraction
    {
        $toolCalls = [];
        foreach (Payload::maps(Payload::get($response, 'message', 'tool_calls')) as $call) {
            $toolCalls[] = new ToolCall(
                Payload::string(Payload::get($call, 'function', 'name')),
                Payload::arguments(Payload::get($call, 'function', 'arguments')),
            );
        }

        return new AiInteraction(
            provider: $this->name(),
            model: Payload::string($response['model'] ?? $request['model'] ?? null),
            promptTokens: Payload::int($response['prompt_eval_count'] ?? null),
            completionTokens: Payload::int($response['eval_count'] ?? null),
            reasoningTokens: 0,
            latencyMs: $latencyMs,
            // /api/chat nests content under message; /api/generate returns it as `response`.
            assistantContent: Payload::string(Payload::get($response, 'message', 'content') ?? $response['response'] ?? null),
            toolCalls: $toolCalls,
        );
    }
}
