<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

use Axitdev\PhpUnitAiEvals\Vcr\AiVcrClient;

/**
 * Serves verdicts of any judge from the cassette, keyed on the judge's cachePayload(); only calls it on a miss.
 *
 * @internal
 */
final class CachedJudgeClient implements JudgeClientInterface
{
    public function __construct(
        private readonly AiVcrClient $vcr,
        private readonly JudgeClientInterface $live,
    ) {}

    public function judge(JudgeRequest $request): JudgeVerdict
    {
        return $this->vcr->judgeVerdict($this->live->cachePayload($request), fn(): JudgeVerdict => $this->live->judge($request));
    }

    public function cachePayload(JudgeRequest $request): array
    {
        return $this->live->cachePayload($request);
    }
}
