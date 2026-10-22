<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace\Parser;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\ToolCall;

final class AnthropicPayloadParser implements ProviderPayloadParserInterface
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function supports(string $uri): bool
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);

        // The second pattern is Claude on Google Vertex AI.
        return str_ends_with($path, '/v1/messages') || preg_match('#/publishers/anthropic/models/[^/:]+:(stream)?rawPredict$#i', $path) === 1;
    }

    /**
     * On Vertex AI the model is in the URL; streaming is still the body's `stream` flag.
     */
    public function requestPayload(string $uri, array $payload): array
    {
        if (!isset($payload['model']) && preg_match('#/publishers/anthropic/models/([^/:]+)#', (string) parse_url($uri, PHP_URL_PATH), $m) === 1) {
            $payload['model'] = $m[1];
        }

        return $payload;
    }

    public function parse(array $request, array $response, float $latencyMs): AiInteraction
    {
        $text = '';
        $toolCalls = [];
        foreach (Payload::maps($response['content'] ?? null) as $block) {
            match ($block['type'] ?? null) {
                'text' => $text .= Payload::string($block['text'] ?? null),
                'tool_use' => $toolCalls[] = new ToolCall(
                    Payload::string($block['name'] ?? null),
                    Payload::arguments($block['input'] ?? null),
                ),
                default => null,
            };
        }

        // Anthropic bills thinking inside output_tokens and reports prompt-cache reads/writes outside input_tokens.
        return new AiInteraction(
            provider: $this->name(),
            model: Payload::string($response['model'] ?? $request['model'] ?? null),
            promptTokens: Payload::int(Payload::get($response, 'usage', 'input_tokens'))
                + Payload::int(Payload::get($response, 'usage', 'cache_read_input_tokens'))
                + Payload::int(Payload::get($response, 'usage', 'cache_creation_input_tokens')),
            completionTokens: Payload::int(Payload::get($response, 'usage', 'output_tokens')),
            reasoningTokens: 0,
            latencyMs: $latencyMs,
            assistantContent: $text,
            toolCalls: $toolCalls,
        );
    }
}
