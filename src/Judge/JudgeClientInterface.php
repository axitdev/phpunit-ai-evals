<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

interface JudgeClientInterface
{
    public function judge(string $systemPrompt, string $userPrompt): JudgeVerdict;
}
