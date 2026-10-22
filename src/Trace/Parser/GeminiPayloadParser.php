<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace\Parser;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\ToolCall;

final class GeminiPayloadParser implements ProviderPayloadParserInterface
{
    public function name(): string
    {
        return 'gemini';
    }

    public function supports(string $uri): bool
    {
        return (bool) preg_match('/:(stream)?generateContent$/i', (string) parse_url($uri, PHP_URL_PATH));
    }

    public function requestPayload(string $uri, array $payload): array
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        if (!isset($payload['model']) && preg_match('#/models/([^/:]+)#', $path, $m) === 1) {
            $payload['model'] = $m[1];
        }
        if (str_ends_with(strtolower($path), ':streamgeneratecontent')) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    public function parse(array $request, array $response, float $latencyMs): AiInteraction
    {
        $text = '';
        $toolCalls = [];
        foreach (Payload::maps(Payload::get($response, 'candidates', 0, 'content', 'parts')) as $part) {
            if (isset($part['text']) && ($part['thought'] ?? false) !== true) {
                $text .= Payload::string($part['text']);
            }
            if (isset($part['functionCall'])) {
                $toolCalls[] = new ToolCall(
                    Payload::string(Payload::get($part, 'functionCall', 'name')),
                    Payload::arguments(Payload::get($part, 'functionCall', 'args')),
                );
            }
        }

        $reasoning = Payload::int(Payload::get($response, 'usageMetadata', 'thoughtsTokenCount'));

        // Gemini reports thought tokens outside candidatesTokenCount; fold them in so completion matches billed output.
        return new AiInteraction(
            provider: $this->name(),
            model: Payload::string($response['modelVersion'] ?? $request['model'] ?? null),
            promptTokens: Payload::int(Payload::get($response, 'usageMetadata', 'promptTokenCount')),
            completionTokens: Payload::int(Payload::get($response, 'usageMetadata', 'candidatesTokenCount')) + $reasoning,
            reasoningTokens: $reasoning,
            latencyMs: $latencyMs,
            assistantContent: $text,
            toolCalls: $toolCalls,
        );
    }
}
