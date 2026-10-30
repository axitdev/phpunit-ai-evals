<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions\Constraints;

use Axitdev\PhpUnitAiEvals\Judge\JudgeClientInterface;
use Axitdev\PhpUnitAiEvals\Judge\JudgeVerdict;
use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use PHPUnit\Framework\Constraint\Constraint;

abstract class JudgedConstraint extends Constraint
{
    private ?JudgeVerdict $verdict = null;

    public function __construct(
        private readonly JudgeClientInterface $judge,
        private readonly float $threshold,
    ) {}

    /** What is asserted, without the threshold: the key under which reports and baselines track the score. */
    abstract protected function description(): string;

    abstract protected function systemPrompt(): string;

    abstract protected function userPrompt(string $output): string;

    protected function matches(mixed $other): bool
    {
        $this->verdict = $this->judge->judge($this->systemPrompt(), $this->userPrompt(is_string($other) ? $other : ''));
        $passed = $this->verdict->score >= $this->threshold;
        TestContextRegistry::recordJudge($this->description(), $this->verdict->score, $this->threshold, $passed, $this->verdict->reason);

        return $passed;
    }

    /** The verdict of the last evaluation, once evaluate() has run. */
    public function verdict(): ?JudgeVerdict
    {
        return $this->verdict;
    }

    public function threshold(): float
    {
        return $this->threshold;
    }

    protected function failureDescription(mixed $other): string
    {
        return 'the output ' . $this->toString();
    }

    protected function additionalFailureDescription(mixed $other): string
    {
        return $this->verdict === null
            ? ''
            : sprintf("Judge score %.2f (threshold %.2f): %s", $this->verdict->score, $this->threshold, $this->verdict->reason);
    }

    public function toString(): string
    {
        return sprintf('%s (%s >= %.2f)', $this->description(), $this->scoreName(), $this->threshold);
    }

    protected function scoreName(): string
    {
        return 'score';
    }
}
