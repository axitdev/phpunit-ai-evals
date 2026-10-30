<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace;

final class AiInteraction
{
    /** @var list<ToolCall> */
    public readonly array $toolCalls;

    public readonly int $totalTokens;

    /**
     * @param array<ToolCall> $toolCalls
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $reasoningTokens,
        public readonly float $latencyMs,
        public readonly string $assistantContent,
        array $toolCalls = [],
    ) {
        $this->toolCalls = array_values($toolCalls);
        $this->totalTokens = $promptTokens + $completionTokens;
    }
}
