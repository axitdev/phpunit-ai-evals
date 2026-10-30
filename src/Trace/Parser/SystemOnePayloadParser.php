<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace\Parser;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\Decision;

/**
 * System One models (TypeSafe Jev) at `POST /v1/systemone`: a state plus typed questions in, typed answers out.
 * The answers become the interaction's decisions; there is no assistant text and there are no tool calls.
 */
final class SystemOnePayloadParser implements ProviderPayloadParserInterface
{
    public function name(): string
    {
        return 'system-one';
    }

    public function supports(string $uri): bool
    {
        return str_ends_with(rtrim((string) parse_url($uri, PHP_URL_PATH), '/'), '/systemone');
    }

    public function requestPayload(string $uri, array $payload): array
    {
        return $payload;
    }

    public function parse(array $request, array $response, float $latencyMs): AiInteraction
    {
        $decisions = [];
        foreach (Payload::map($response['answers'] ?? null) as $id => $answer) {
            $decisions[] = Decision::fromAnswer($id, Payload::map($answer));
        }

        return new AiInteraction(
            provider: $this->name(),
            // The response names the release that answered (jev-1.13.0); the request may only say jev-latest.
            model: Payload::string($response['model'] ?? $request['model'] ?? null),
            promptTokens: Payload::int(Payload::get($response, 'usage', 'input_tokens')),
            completionTokens: Payload::int(Payload::get($response, 'usage', 'output_tokens')),
            reasoningTokens: 0,
            latencyMs: $latencyMs,
            assistantContent: '',
            decisions: $decisions,
        );
    }
}
