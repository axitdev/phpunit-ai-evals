<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

use Closure;

/**
 * Builds the real judge on the first judge() call, so a verdict served from the cassette never resolves settings,
 * API keys or HTTP factories. A plain wrapper rather than a PHP 8.4 lazy proxy, to run on PHP 8.3.
 *
 * @internal
 */
final class LazyJudgeClient implements JudgeClientInterface
{
    private ?JudgeClientInterface $judge = null;

    /**
     * @param Closure(): JudgeClientInterface $factory
     */
    public function __construct(private readonly Closure $factory) {}

    public function judge(string $systemPrompt, string $userPrompt): JudgeVerdict
    {
        return ($this->judge ??= ($this->factory)())->judge($systemPrompt, $userPrompt);
    }
}
