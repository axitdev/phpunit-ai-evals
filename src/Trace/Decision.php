<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Trace;

use Axitdev\PhpUnitAiEvals\Support\Payload;

/**
 * One typed answer from a System One model, under the question id the request gave it.
 *
 * - choice: $value is the chosen option, $probabilities has one entry per option
 * - score: $value is the probability-weighted level (0 to the top level), $probabilities has one entry per level
 * - noul: $value is the probability of "yes"; there are no $probabilities and no $confidence
 */
final readonly class Decision
{
    public const string CHOICE = 'choice';

    public const string SCORE = 'score';

    public const string NOUL = 'noul';

    /**
     * @param array<string, float> $probabilities
     */
    public function __construct(
        public string $id,
        public string $type,
        public string|float $value,
        public array $probabilities = [],
        public ?float $confidence = null,
    ) {}

    /**
     * @param array<string, mixed> $answer
     */
    public static function fromAnswer(string $id, array $answer): self
    {
        $type = Payload::string($answer['type'] ?? null);
        $probabilities = array_map(Payload::float(...), Payload::map($answer['probabilities'] ?? null));

        return new self(
            $id,
            $type,
            match ($type) {
                self::CHOICE => Payload::string($answer['choice'] ?? null),
                self::SCORE => Payload::float($answer['score'] ?? null),
                default => Payload::float($answer[$type] ?? null),
            },
            $probabilities,
            isset($answer['confidence']) ? Payload::float($answer['confidence']) : null,
        );
    }

    public function probabilityOf(string $option): float
    {
        return $this->probabilities[$option] ?? 0.0;
    }

    /**
     * The top level of a Score scale (levels are numbered from 0), so that $value / topLevel() is on 0 to 1.
     */
    public function topLevel(): int
    {
        return max(0, count($this->probabilities) - 1);
    }
}
