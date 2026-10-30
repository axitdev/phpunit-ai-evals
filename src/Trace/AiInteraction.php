<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace;

final class AiInteraction
{
    /** @var list<ToolCall> */
    public private(set) array $toolCalls = [];

    /** @var array<string, Decision> System One answers, by question id */
    public private(set) array $decisions = [];

    public int $totalTokens {
        get => $this->promptTokens + $this->completionTokens;
    }

    /**
     * @param array<ToolCall> $toolCalls
     * @param array<Decision> $decisions
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
        array $decisions = [],
    ) {
        $this->toolCalls = array_values($toolCalls);
        foreach ($decisions as $decision) {
            $this->decisions[$decision->id] = $decision;
        }
    }
}
