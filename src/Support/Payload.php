<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Support;

/**
 * @internal
 */
final class Payload
{
    public static function get(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    public static function int(mixed $value): int
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (int) $value : 0;
    }

    public static function float(mixed $value): float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function maps(mixed $value): array
    {
        return array_values(array_map(self::map(...), array_filter(is_array($value) ? $value : [], is_array(...))));
    }

    /**
     * Tool arguments arrive as an object or as a JSON-encoded string depending on the provider.
     *
     * @return array<string, mixed>
     */
    public static function arguments(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return self::map($value);
    }
}
