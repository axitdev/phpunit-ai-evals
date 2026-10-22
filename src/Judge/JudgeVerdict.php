<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Judge;

use InvalidArgumentException;

final readonly class JudgeVerdict
{
    public function __construct(
        public float $score,
        public string $reason,
    ) {
        if ($score < 0.0 || $score > 1.0) {
            throw new InvalidArgumentException(sprintf('Judge score must be within 0.0-1.0, got %s.', $score));
        }
    }
}
