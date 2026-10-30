<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Conversation;

use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use OutOfRangeException;

/**
 * The history of a scripted conversation. The agent under test reads it to build its next request, and each turn's
 * assertions read it to check the conversation so far.
 */
final class Conversation
{
    /** @var list<TurnResult> */
    private array $turns = [];

    private ?string $pending = null;

    public function __construct(public readonly string $name) {}

    /**
     * Every message so far as `['role' => 'user'|'assistant', 'content' => string]`, in order. While the agent is
     * answering, the last entry is the user message it is answering.
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->turns as $turn) {
            $messages[] = ['role' => 'user', 'content' => $turn->message];
            $messages[] = ['role' => 'assistant', 'content' => $turn->reply];
        }
        if ($this->pending !== null) {
            $messages[] = ['role' => 'user', 'content' => $this->pending];
        }

        return $messages;
    }

    /**
     * @return list<TurnResult>
     */
    public function turns(): array
    {
        return $this->turns;
    }

    /**
     * @param int $index 1-based
     */
    public function turn(int $index): TurnResult
    {
        return $this->turns[$index - 1] ?? throw new OutOfRangeException(sprintf(
            'Conversation "%s" has no turn %d yet (it has %d).',
            $this->name,
            $index,
            count($this->turns),
        ));
    }

    public function lastReply(): string
    {
        return $this->turns === [] ? '' : $this->turns[count($this->turns) - 1]->reply;
    }

    /**
     * Every LLM call of the conversation so far, across turns.
     *
     * @return list<AiInteraction>
     */
    public function interactions(): array
    {
        return array_merge([], ...array_map(static fn(TurnResult $t): array => $t->interactions, $this->turns));
    }

    /**
     * @internal
     */
    public function begin(string $message): void
    {
        $this->pending = $message;
    }

    /**
     * @internal
     *
     * @param list<AiInteraction> $interactions
     */
    public function complete(string $reply, array $interactions): TurnResult
    {
        $turn = new TurnResult(count($this->turns) + 1, (string) $this->pending, $reply, $interactions);
        $this->turns[] = $turn;
        $this->pending = null;

        return $turn;
    }
}
