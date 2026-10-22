<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions\Constraints;

use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use PHPUnit\Framework\Constraint\Constraint;

final class ToolCalledConstraint extends Constraint
{
    /**
     * @param array<string, mixed>|null $expectedArgumentsSubset
     */
    public function __construct(
        private readonly string $toolName,
        private readonly ?array $expectedArgumentsSubset = null,
        private readonly ?int $times = null,
    ) {}

    public function toString(): string
    {
        $args = $this->expectedArgumentsSubset === null
            ? ''
            : ' with arguments containing ' . json_encode($this->expectedArgumentsSubset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $times = $this->times === null ? '' : sprintf(' exactly %d time%s', $this->times, $this->times === 1 ? '' : 's');

        return sprintf('called tool "%s"%s%s', $this->toolName, $args, $times);
    }

    protected function matches(mixed $other): bool
    {
        $count = 0;
        foreach (is_array($other) ? $other : [] as $interaction) {
            if (!$interaction instanceof AiInteraction) {
                continue;
            }
            foreach ($interaction->toolCalls as $call) {
                if ($call->name === $this->toolName
                    && ($this->expectedArgumentsSubset === null || self::isSubset($this->expectedArgumentsSubset, $call->arguments))) {
                    $count++;
                }
            }
        }

        return $this->times === null ? $count > 0 : $count === $this->times;
    }

    protected function failureDescription(mixed $other): string
    {
        $called = [];
        foreach (is_array($other) ? $other : [] as $interaction) {
            if ($interaction instanceof AiInteraction) {
                foreach ($interaction->toolCalls as $call) {
                    $called[] = $call->name . '(' . json_encode($call->arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ')';
                }
            }
        }

        return 'the agent trace ' . $this->toString() . ($called === [] ? ' (no tool calls were made)' : ' (calls made: ' . implode(', ', $called) . ')');
    }

    /**
     * Recursive subset match: extra keys in $actual are ignored; expected lists match order-insensitively, each item
     * against a different actual item. A nested empty array only matches an empty one.
     *
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    public static function isSubset(array $expected, array $actual): bool
    {
        if (array_is_list($expected)) {
            foreach ($expected as $item) {
                $found = false;
                foreach ($actual as $i => $candidate) {
                    if (self::valueMatches($item, $candidate)) {
                        unset($actual[$i]);
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }

            return true;
        }

        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || !self::valueMatches($value, $actual[$key])) {
                return false;
            }
        }

        return true;
    }

    private static function valueMatches(mixed $expected, mixed $actual): bool
    {
        if (is_array($expected)) {
            return is_array($actual) && ($expected === [] ? $actual === [] : self::isSubset($expected, $actual));
        }

        return $expected === $actual || (is_numeric($expected) && is_numeric($actual) && !is_string($expected) && !is_string($actual) && $expected == $actual);
    }
}
