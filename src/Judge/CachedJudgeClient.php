<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

use Axitdev\PhpUnitAiEvals\Vcr\AiVcrClient;

/**
 * Serves verdicts from the cassette; only touches $live (typically a LazyJudgeClient) on a cache miss.
 *
 * @internal
 */
final class CachedJudgeClient implements JudgeClientInterface
{
    public function __construct(
        private readonly AiVcrClient $vcr,
        private readonly JudgeClientInterface $live,
        private readonly string $model,
    ) {}

    public function judge(string $systemPrompt, string $userPrompt): JudgeVerdict
    {
        return $this->vcr->judgeVerdict(
            [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ],
            fn(): JudgeVerdict => $this->live->judge($systemPrompt, $userPrompt),
        );
    }
}
