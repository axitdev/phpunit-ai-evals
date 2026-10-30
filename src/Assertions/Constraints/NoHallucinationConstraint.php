<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions\Constraints;

use Axitdev\PhpUnitAiEvals\Judge\JudgeClientInterface;
use Axitdev\PhpUnitAiEvals\Judge\JudgeRequest;

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

    protected function request(string $output): JudgeRequest
    {
        return JudgeRequest::grounding($this->context, $output);
    }
}
