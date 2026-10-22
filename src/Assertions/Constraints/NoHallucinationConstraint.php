<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions\Constraints;

use Axitdev\PhpUnitAiEvals\Judge\JudgeClientInterface;
use Axitdev\PhpUnitAiEvals\Judge\JudgePrompts;

final class NoHallucinationConstraint extends JudgedConstraint
{
    /**
     * @param list<string> $context
     */
    public function __construct(JudgeClientInterface $judge, private readonly array $context, float $threshold = 0.85)
    {
        parent::__construct($judge, $threshold);
    }

    protected function description(): string
    {
        return 'is grounded in the given context';
    }

    protected function scoreName(): string
    {
        return 'hallucination score';
    }

    protected function systemPrompt(): string
    {
        return JudgePrompts::HALLUCINATION_SYSTEM;
    }

    protected function userPrompt(string $output): string
    {
        return JudgePrompts::hallucination($this->context, $output);
    }
}
