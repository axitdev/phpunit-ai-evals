<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Support;

use Axitdev\PhpUnitAiEvals\Judge\JudgeClientInterface;
use Axitdev\PhpUnitAiEvals\Judge\JudgeVerdict;

final class SpyJudge implements JudgeClientInterface
{
    public string $system = '';

    public string $user = '';

    public function __construct(private readonly float $score, private readonly string $reason = 'because') {}

    public function judge(string $systemPrompt, string $userPrompt): JudgeVerdict
    {
        $this->system = $systemPrompt;
        $this->user = $userPrompt;

        return new JudgeVerdict($this->score, $this->reason);
    }
}
