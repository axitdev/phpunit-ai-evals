<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace\Parser;

use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;

interface ProviderPayloadParserInterface
{
    public function name(): string;

    /**
     * Whether the request URI targets this provider's chat/generation endpoint.
     */
    public function supports(string $uri): bool;

    /**
     * Returns the decoded request body plus whatever the provider carries in the URL instead (such as the model or
     * streaming), so that it becomes part of the semantic hash. Most providers return $payload unchanged.
     * A result without a `model` key or a prompt (`messages`, `contents`, `input` or `prompt`) is recorded as raw HTTP.
     *
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    public function requestPayload(string $uri, array $payload): array;

    /**
     * Builds the trace from a decoded (non-streaming shaped) response.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $response
     */
    public function parse(array $request, array $response, float $latencyMs): AiInteraction;
}
