<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr;

use InvalidArgumentException;

enum VcrMode: string
{
    case Record = 'record';
    case Replay = 'replay';
    case Auto = 'auto';
    case Live = 'live';

    /**
     * Applies the AI_EVAL_MODE override, then downgrades Auto to strict Replay on CI.
     */
    public static function resolve(self $configured): self
    {
        $env = getenv('AI_EVAL_MODE');
        $mode = is_string($env) && $env !== '' ? self::parse($env, 'AI_EVAL_MODE') : $configured;

        return $mode === self::Auto && (self::flag('CI') || self::flag('GITHUB_ACTIONS')) ? self::Replay : $mode;
    }

    public static function parse(string $value, string $source): self
    {
        return self::tryFrom(strtolower($value))
            ?? throw new InvalidArgumentException(sprintf(
                'Invalid %s "%s"; expected one of: %s.',
                $source,
                $value,
                implode(', ', array_map(static fn(self $m): string => $m->value, self::cases())),
            ));
    }

    public function replays(): bool
    {
        return $this === self::Replay || $this === self::Auto;
    }

    public function records(): bool
    {
        return $this === self::Record || $this === self::Auto;
    }

    /**
     * `false`, `0` and empty count as off.
     *
     * @internal
     */
    public static function flag(string $name): bool
    {
        $value = getenv($name);

        return is_string($value) && !in_array(strtolower($value), ['', '0', 'false'], true);
    }
}
