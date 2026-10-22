<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions\Constraints;

use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Closure;
use PHPUnit\Framework\Constraint\Constraint;

final class TraceBudgetConstraint extends Constraint
{
    /**
     * @param Closure(AiInteraction): (int|float) $metric
     */
    private function __construct(
        private readonly string $unit,
        private readonly string $format,
        private readonly Closure $metric,
        private readonly int|float $max,
    ) {}

    public static function tokens(int $maxTotalTokens): self
    {
        return new self('total tokens', '%d', static fn(AiInteraction $i): int => $i->totalTokens, $maxTotalTokens);
    }

    public static function latency(float $maxMs): self
    {
        return new self('ms of model latency', '%.1f', static fn(AiInteraction $i): float => $i->latencyMs, $maxMs);
    }

    public function toString(): string
    {
        return sprintf('uses fewer than ' . $this->format . ' %s', $this->max, $this->unit);
    }

    protected function matches(mixed $other): bool
    {
        return $this->total($other) < $this->max;
    }

    protected function failureDescription(mixed $other): string
    {
        return sprintf('the agent trace (' . $this->format . ' %s) %s', $this->total($other), $this->unit, $this->toString());
    }

    private function total(mixed $interactions): int|float
    {
        $total = 0;
        foreach (is_array($interactions) ? $interactions : [] as $interaction) {
            if ($interaction instanceof AiInteraction) {
                $total += ($this->metric)($interaction);
            }
        }

        return $total;
    }
}
