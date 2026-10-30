<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions\Constraints;

use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\Decision;
use Closure;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Checks the latest System One answer to one question across a list of interactions.
 */
final class DecisionConstraint extends Constraint
{
    private ?Decision $found = null;

    /**
     * @param Closure(Decision): bool $check
     */
    private function __construct(
        private readonly string $questionId,
        /** null: any question type */
        private readonly ?string $expectedType,
        private readonly string $expectation,
        private readonly Closure $check,
    ) {}

    public static function choice(string $questionId, string $option, float $minProbability = 0.0): self
    {
        return new self(
            $questionId,
            Decision::CHOICE,
            $minProbability > 0.0 ? sprintf('chose "%s" with probability >= %.2f', $option, $minProbability) : sprintf('chose "%s"', $option),
            static fn(Decision $d): bool => $d->value === $option && $d->probabilityOf($option) >= $minProbability,
        );
    }

    public static function noulAtLeast(string $questionId, float $min): self
    {
        return new self($questionId, Decision::NOUL, sprintf('answered yes with probability >= %.2f', $min), static fn(Decision $d): bool => $d->value >= $min);
    }

    public static function noulAtMost(string $questionId, float $max): self
    {
        return new self($questionId, Decision::NOUL, sprintf('answered yes with probability <= %.2f', $max), static fn(Decision $d): bool => $d->value <= $max);
    }

    public static function scoreBetween(string $questionId, float $min, float $max): self
    {
        return new self($questionId, Decision::SCORE, sprintf('scored between %.2f and %.2f', $min, $max), static fn(Decision $d): bool => $d->value >= $min && $d->value <= $max);
    }

    public static function confidenceAtLeast(string $questionId, float $min): self
    {
        return new self($questionId, null, sprintf('answered with confidence >= %.2f', $min), static fn(Decision $d): bool => $d->confidence !== null && $d->confidence >= $min);
    }

    /**
     * The answer from the most recent interaction that asked this question.
     *
     * @param list<AiInteraction> $interactions
     */
    public static function latest(array $interactions, string $questionId): ?Decision
    {
        foreach (array_reverse($interactions) as $interaction) {
            if (isset($interaction->decisions[$questionId])) {
                return $interaction->decisions[$questionId];
            }
        }

        return null;
    }

    protected function matches(mixed $other): bool
    {
        /** @var list<AiInteraction> $other */
        $this->found = self::latest($other, $this->questionId);

        return $this->found !== null
            && ($this->expectedType === null || $this->found->type === $this->expectedType)
            && ($this->check)($this->found);
    }

    protected function failureDescription(mixed $other): string
    {
        return 'the System One question "' . $this->questionId . '" ' . $this->expectation;
    }

    protected function additionalFailureDescription(mixed $other): string
    {
        $d = $this->found;
        if ($d === null) {
            return 'No recorded System One answer has that question id.';
        }
        if ($this->expectedType !== null && $d->type !== $this->expectedType) {
            return sprintf('It is a %s question, not a %s question.', $d->type, $this->expectedType);
        }

        return sprintf(
            'Answer: %s %s%s%s',
            $d->type,
            is_string($d->value) ? '"' . $d->value . '"' : sprintf('%.2f', $d->value),
            $d->confidence !== null ? sprintf(', confidence %.2f', $d->confidence) : '',
            $d->probabilities !== [] ? ', probabilities ' . json_encode($d->probabilities, JSON_FORCE_OBJECT) : '',
        );
    }

    public function toString(): string
    {
        return $this->expectation;
    }
}
