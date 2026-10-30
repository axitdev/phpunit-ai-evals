<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions\Constraints;

use Axitdev\PhpUnitAiEvals\Judge\JudgeClientInterface;
use Axitdev\PhpUnitAiEvals\Judge\JudgeRequest;

final class MatchesRubricConstraint extends JudgedConstraint
{
    /**
     * @param string|list<string> $rubric One criterion, or several that must all hold
     */
    public function __construct(JudgeClientInterface $judge, private readonly string|array $rubric, float $threshold = 0.80)
    {
        parent::__construct($judge, $threshold);
        JudgeRequest::rubric($rubric, ''); // fail on an empty rubric when the assertion is written, not when it runs
    }

    protected function description(): string
    {
        return 'satisfies the rubric "' . (is_string($this->rubric) ? $this->rubric : implode('; ', $this->rubric)) . '"';
    }

    protected function request(string $output): JudgeRequest
    {
        return JudgeRequest::rubric($this->rubric, $output);
    }
}
