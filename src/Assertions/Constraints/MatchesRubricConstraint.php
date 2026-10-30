<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions\Constraints;

use Axitdev\PhpUnitAiEvals\Judge\JudgeClientInterface;
use Axitdev\PhpUnitAiEvals\Judge\JudgeRequest;

final class MatchesRubricConstraint extends JudgedConstraint
{
    /** @var list<string> */
    private readonly array $criteria;

    /**
     * @param string|list<string> $rubric One criterion, or several that must all hold
     */
    public function __construct(JudgeClientInterface $judge, string|array $rubric, float $threshold = 0.80)
    {
        parent::__construct($judge, $threshold);
        // Normalized (and an empty rubric rejected) when the assertion is written, not when it runs.
        $this->criteria = JudgeRequest::rubric($rubric, '')->criteria;
    }

    protected function description(): string
    {
        return 'satisfies the rubric "' . implode('; ', $this->criteria) . '"';
    }

    protected function request(string $output): JudgeRequest
    {
        return JudgeRequest::rubric($this->criteria, $output);
    }
}
