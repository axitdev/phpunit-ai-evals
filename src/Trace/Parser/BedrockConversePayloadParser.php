<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace\Parser;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\ToolCall;

/**
 * Amazon Bedrock Converse and ConverseStream; the binary event stream is decoded by StreamRebuilder.
 */
final class BedrockConversePayloadParser implements ProviderPayloadParserInterface
{
    public function name(): string
    {
        return 'bedrock';
    }

    public function supports(string $uri): bool
    {
        return preg_match('#/model/[^/]+/converse(-stream)?$#', (string) parse_url($uri, PHP_URL_PATH)) === 1;
    }

    public function requestPayload(string $uri, array $payload): array
    {
        if (preg_match('#/model/([^/]+)/converse(-stream)?$#', (string) parse_url($uri, PHP_URL_PATH), $m) === 1) {
            $payload['model'] = rawurldecode($m[1]);
            if (($m[2] ?? '') !== '') {
                $payload['stream'] = true;
            }
        }

        return $payload;
    }

    public function parse(array $request, array $response, float $latencyMs): AiInteraction
    {
        $text = '';
        $toolCalls = [];
        foreach (Payload::maps(Payload::get($response, 'output', 'message', 'content')) as $block) {
            $text .= Payload::string($block['text'] ?? null);
            if (isset($block['toolUse'])) {
                $toolCalls[] = new ToolCall(Payload::string(Payload::get($block, 'toolUse', 'name')), Payload::arguments(Payload::get($block, 'toolUse', 'input')));
            }
        }

        return new AiInteraction(
            provider: $this->name(),
            model: Payload::string($request['model'] ?? null),
            promptTokens: Payload::int(Payload::get($response, 'usage', 'inputTokens'))
                + Payload::int(Payload::get($response, 'usage', 'cacheReadInputTokens'))
                + Payload::int(Payload::get($response, 'usage', 'cacheWriteInputTokens')),
            completionTokens: Payload::int(Payload::get($response, 'usage', 'outputTokens')),
            reasoningTokens: 0,
            latencyMs: $latencyMs,
            assistantContent: $text,
            toolCalls: $toolCalls,
        );
    }
}
