<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

use InvalidArgumentException;

/**
 * What a judge is asked to grade, independent of how a judge model is prompted.
 *
 * A rubric holds one or more criteria that must all hold. Pass one criterion per thing being checked: a judge that
 * answers one question at a time (such as a System One model) grades each separately and reports the weakest.
 */
final readonly class JudgeRequest
{
    public const string RUBRIC = 'rubric';

    public const string GROUNDING = 'grounding';

    /**
     * @param list<string> $criteria non-empty for a rubric, empty for grounding
     * @param list<string> $context
     */
    private function __construct(
        public string $kind,
        public array $criteria,
        public array $context,
        public string $output,
    ) {}

    /**
     * @param string|list<string> $rubric
     */
    public static function rubric(string|array $rubric, string $output): self
    {
        $criteria = array_values(array_filter(array_map(trim(...), is_string($rubric) ? [$rubric] : $rubric), static fn(string $c): bool => $c !== ''));
        if ($criteria === []) {
            throw new InvalidArgumentException('A rubric needs at least one non-empty criterion.');
        }

        return new self(self::RUBRIC, $criteria, [], $output);
    }

    /**
     * @param list<string> $context
     */
    public static function grounding(array $context, string $output): self
    {
        return new self(self::GROUNDING, [], $context, $output);
    }
}
