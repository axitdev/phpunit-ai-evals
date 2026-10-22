<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace\Parser;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\ToolCall;

final class OpenAiResponsesPayloadParser implements ProviderPayloadParserInterface
{
    public function name(): string
    {
        return 'openai-responses';
    }

    public function supports(string $uri): bool
    {
        return str_ends_with((string) parse_url($uri, PHP_URL_PATH), '/responses');
    }

    public function requestPayload(string $uri, array $payload): array
    {
        return $payload;
    }

    public function parse(array $request, array $response, float $latencyMs): AiInteraction
    {
        $text = '';
        $toolCalls = [];
        foreach (Payload::maps($response['output'] ?? null) as $item) {
            if (($item['type'] ?? null) === 'message') {
                foreach (Payload::maps($item['content'] ?? null) as $part) {
                    $text .= ($part['type'] ?? null) === 'output_text' ? Payload::string($part['text'] ?? null) : '';
                }
            } elseif (($item['type'] ?? null) === 'function_call') {
                $toolCalls[] = new ToolCall(Payload::string($item['name'] ?? null), Payload::arguments($item['arguments'] ?? null));
            }
        }

        return new AiInteraction(
            provider: $this->name(),
            model: Payload::string($response['model'] ?? $request['model'] ?? null),
            promptTokens: Payload::int(Payload::get($response, 'usage', 'input_tokens')),
            completionTokens: Payload::int(Payload::get($response, 'usage', 'output_tokens')),
            reasoningTokens: Payload::int(Payload::get($response, 'usage', 'output_tokens_details', 'reasoning_tokens')),
            latencyMs: $latencyMs,
            assistantContent: $text,
            toolCalls: $toolCalls,
        );
    }
}
