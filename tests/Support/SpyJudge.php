<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Support;

use Axitdev\PhpUnitAiEvals\Judge\JudgeClientInterface;
use Axitdev\PhpUnitAiEvals\Judge\JudgePrompts;
use Axitdev\PhpUnitAiEvals\Judge\JudgeRequest;
use Axitdev\PhpUnitAiEvals\Judge\JudgeVerdict;

final class SpyJudge implements JudgeClientInterface
{
    public ?JudgeRequest $request = null;

    /** The prompts an LLM judge would have been sent */
    public string $system = '';

    public string $user = '';

    public int $calls = 0;

    public function __construct(private readonly float $score, private readonly string $reason = 'because') {}

    public function judge(JudgeRequest $request): JudgeVerdict
    {
        $this->calls++;
        $this->request = $request;
        [$this->system, $this->user] = JudgePrompts::forRequest($request);

        return new JudgeVerdict($this->score, $this->reason);
    }

    public function cachePayload(JudgeRequest $request): array
    {
        return ['model' => 'spy', 'request' => (array) $request];
    }
}
