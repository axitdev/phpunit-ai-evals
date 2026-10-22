<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace\Parser;

/**
 * A custom parser for a provider that streams. Recorded chunks are the JSON objects of its SSE `data:` or NDJSON lines.
 */
interface StreamingPayloadParserInterface extends ProviderPayloadParserInterface
{
    /**
     * Merges the recorded chunks into the non-streaming response shape that parse() reads.
     *
     * @param list<array<string, mixed>> $chunks
     * @return array<string, mixed>
     */
    public function mergeStream(array $chunks): array;

    /**
     * Renders the recorded chunks in the provider's wire format, as the replayed response body.
     *
     * @param list<array<string, mixed>> $chunks
     */
    public function renderStream(array $chunks): string;
}
