<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

interface JudgeClientInterface
{
    public function judge(JudgeRequest $request): JudgeVerdict;

    /**
     * Identifies the request in the cassette so its verdict can be replayed: normally exactly what would be sent to
     * the judge model, including the model name. Two requests with the same payload share a verdict, so everything
     * that can change the verdict must be in it. Must not make network calls.
     *
     * @return array<string, mixed>
     */
    public function cachePayload(JudgeRequest $request): array;
}
