<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions\Constraints;

use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use PHPUnit\Framework\Constraint\Constraint;

final class ToolCallOrderConstraint extends Constraint
{
    /**
     * @param list<string> $toolNames
     */
    public function __construct(private readonly array $toolNames) {}

    public function toString(): string
    {
        return 'called tools in order ' . implode(' -> ', $this->toolNames);
    }

    protected function matches(mixed $other): bool
    {
        $next = 0;
        foreach (self::calledNames($other) as $name) {
            if ($next < count($this->toolNames) && $name === $this->toolNames[$next]) {
                $next++;
            }
        }

        return $next === count($this->toolNames);
    }

    protected function failureDescription(mixed $other): string
    {
        $called = self::calledNames($other);

        return 'the agent trace ' . $this->toString() . ($called === [] ? ' (no tool calls were made)' : ' (actual order: ' . implode(' -> ', $called) . ')');
    }

    /**
     * @return list<string>
     */
    private static function calledNames(mixed $interactions): array
    {
        $names = [];
        foreach (is_array($interactions) ? $interactions : [] as $interaction) {
            if ($interaction instanceof AiInteraction) {
                foreach ($interaction->toolCalls as $call) {
                    $names[] = $call->name;
                }
            }
        }

        return $names;
    }
}
