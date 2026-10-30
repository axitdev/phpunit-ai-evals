<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions\Constraints;

use Axitdev\PhpUnitAiEvals\Judge\JudgeClientInterface;
use Axitdev\PhpUnitAiEvals\Judge\JudgePrompts;

/**
 * Same meaning as a reference answer, in any language: an English reference can grade a Polish or Ukrainian output.
 */
final class SemanticSimilarityConstraint extends JudgedConstraint
{
    public function __construct(
        JudgeClientInterface $judge,
        private readonly string $reference,
        float $threshold = 0.80,
        private readonly ?string $variant = null,
    ) {
        parent::__construct($judge, $threshold);
    }

    protected function description(): string
    {
        $reference = mb_strlen($this->reference) > 80 ? mb_substr($this->reference, 0, 77) . '...' : $this->reference;

        return 'means the same as "' . $reference . '"' . ($this->variant !== null ? ' [' . $this->variant . ']' : '');
    }

    protected function systemPrompt(): string
    {
        return JudgePrompts::SIMILARITY_SYSTEM;
    }

    protected function userPrompt(string $output): string
    {
        return JudgePrompts::similarity($this->reference, $output);
    }

    protected function scoreName(): string
    {
        return 'similarity';
    }
}
