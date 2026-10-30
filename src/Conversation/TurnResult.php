<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Conversation;

use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;

final class TurnResult
{
    /**
     * @param int $index 1-based position in the conversation
     * @param list<AiInteraction> $interactions The LLM calls made while answering this turn, tool-call rounds included
     */
    public function __construct(
        public readonly int $index,
        public readonly string $message,
        public readonly string $reply,
        public readonly array $interactions,
    ) {}
}
