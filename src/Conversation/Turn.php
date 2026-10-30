<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Conversation;

use Closure;

/**
 * One scripted user message of a conversation eval, with the assertions to run on the reply.
 */
final class Turn
{
    /**
     * @param (Closure(string, Conversation): void)|null $assert
     */
    private function __construct(
        public readonly string $message,
        public readonly ?Closure $assert,
    ) {}

    /**
     * @param (callable(string $reply, Conversation $conversation): void)|null $assert Runs after the reply; the
     *        conversation holds the full history, this turn included, and the AI interactions of every turn
     */
    public static function user(string $message, ?callable $assert = null): self
    {
        return new self($message, $assert === null ? null : $assert(...));
    }
}
