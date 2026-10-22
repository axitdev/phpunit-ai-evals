<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace\Parser;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\ToolCall;

/**
 * OpenAI Chat Completions and compatible APIs (Azure OpenAI, Groq, DeepSeek, vLLM, ...).
 */
final class OpenAiPayloadParser implements ProviderPayloadParserInterface
{
    public function name(): string
    {
        return 'openai';
    }

    public function supports(string $uri): bool
    {
        return str_ends_with((string) parse_url($uri, PHP_URL_PATH), '/chat/completions');
    }

    /**
     * Azure OpenAI names the deployment in the URL (`/openai/deployments/{name}/chat/completions`) instead of a model.
     */
    public function requestPayload(string $uri, array $payload): array
    {
        if (!isset($payload['model']) && preg_match('#/openai/deployments/([^/]+)/chat/completions$#', (string) parse_url($uri, PHP_URL_PATH), $m) === 1) {
            $payload['model'] = rawurldecode($m[1]);
        }

        return $payload;
    }

    public function parse(array $request, array $response, float $latencyMs): AiInteraction
    {
        $message = Payload::get($response, 'choices', 0, 'message');
        $toolCalls = [];
        foreach (Payload::maps(Payload::get($message, 'tool_calls')) as $call) {
            $toolCalls[] = new ToolCall(
                Payload::string(Payload::get($call, 'function', 'name')),
                Payload::arguments(Payload::get($call, 'function', 'arguments')),
            );
        }

        return new AiInteraction(
            provider: $this->name(),
            model: Payload::string($response['model'] ?? $request['model'] ?? null),
            promptTokens: Payload::int(Payload::get($response, 'usage', 'prompt_tokens')),
            completionTokens: Payload::int(Payload::get($response, 'usage', 'completion_tokens')),
            reasoningTokens: Payload::int(Payload::get($response, 'usage', 'completion_tokens_details', 'reasoning_tokens')),
            latencyMs: $latencyMs,
            assistantContent: Payload::string(Payload::get($message, 'content')),
            toolCalls: $toolCalls,
        );
    }
}
