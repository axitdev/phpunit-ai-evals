<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions\Constraints;

use Axitdev\PhpUnitAiEvals\Judge\JudgeClientInterface;
use Axitdev\PhpUnitAiEvals\Judge\JudgePrompts;

final class MatchesRubricConstraint extends JudgedConstraint
{
    public function __construct(JudgeClientInterface $judge, private readonly string $rubric, float $threshold = 0.80)
    {
        parent::__construct($judge, $threshold);
    }

    protected function description(): string
    {
        return 'satisfies the rubric "' . $this->rubric . '"';
    }

    protected function systemPrompt(): string
    {
        return JudgePrompts::RUBRIC_SYSTEM;
    }

    protected function userPrompt(string $output): string
    {
        return JudgePrompts::rubric($this->rubric, $output);
    }
}
