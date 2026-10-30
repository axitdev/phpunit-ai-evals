<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr\Exception;

use RuntimeException;

final class CassetteMissException extends RuntimeException
{
    private const MAX_FIELDS = 12;

    private const MAX_VALUE_LENGTH = 160;

    /**
     * @param array<string, mixed> $current Normalized payload of the request that missed
     * @param array<string, mixed>|null $closest Normalized payload of the closest recorded interaction
     */
    public static function create(
        string $cassettePath,
        string $testName,
        string $kind,
        string $hash,
        int $occurrence,
        array $current,
        ?array $closest,
        bool $hashRecorded,
    ): self {
        $lines = [
            sprintf(
                $hashRecorded
                    ? 'Cassette has %s %s but not occurrence #%d (the request was sent more often than recorded).'
                    : 'No recorded %s with semantic_hash %s (occurrence #%d) in cassette.',
                $kind,
                $hash,
                $occurrence,
            ),
            'Cassette: ' . $cassettePath,
        ];
        if ($closest !== null) {
            $fields = self::fieldDiff($closest, $current);
            $lines[] = '';
            if ($fields === []) {
                $lines[] = 'The closest recorded interaction has the same fields and values; only their order differs.';
            } else {
                $lines[] = sprintf('Differs from the closest recorded interaction in: %s', implode(', ', array_keys($fields)));
                foreach (array_slice($fields, 0, self::MAX_FIELDS, true) as $path => [$recorded, $now]) {
                    $lines[] = '  ' . $path;
                    $lines[] = '    recorded: ' . $recorded;
                    $lines[] = '    current:  ' . $now;
                }
                if (count($fields) > self::MAX_FIELDS) {
                    $lines[] = sprintf('  ... and %d more', count($fields) - self::MAX_FIELDS);
                }
            }
            $lines[] = 'Fields can be ignored or normalized with #[AiVcr(ignoreFields: [...], normalizeFields: [...])].';
        }
        $lines[] = '';
        $lines[] = 'Re-record with: AI_EVAL_MODE=record vendor/bin/phpunit --filter ' . self::filterFor($testName);

        return new self(implode("\n", $lines));
    }

    /**
     * Dot path of every differing field => [recorded, current], rendered. A subtree present on one side only is reported
     * once at its root rather than leaf by leaf.
     *
     * @param array<mixed> $recorded
     * @param array<mixed> $current
     * @return array<string, array{string, string}>
     */
    private static function fieldDiff(array $recorded, array $current, string $prefix = ''): array
    {
        $out = [];
        $keys = array_keys($recorded + $current);
        foreach ($keys as $key) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $hasOld = array_key_exists($key, $recorded);
            $hasNew = array_key_exists($key, $current);
            $old = $recorded[$key] ?? null;
            $new = $current[$key] ?? null;
            if ($hasOld && $hasNew && is_array($old) && is_array($new) && $old !== [] && $new !== []) {
                $out += self::fieldDiff($old, $new, $path);
            } elseif (!$hasOld || !$hasNew || self::render($old) !== self::render($new)) {
                $out[$path] = [$hasOld ? self::render($old) : '(absent)', $hasNew ? self::render($new) : '(absent)'];
            }
        }

        return $out;
    }

    private static function render(mixed $value): string
    {
        $json = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return mb_strlen($json) > self::MAX_VALUE_LENGTH ? mb_substr($json, 0, self::MAX_VALUE_LENGTH - 3) . '...' : $json;
    }

    /**
     * `ShortClass::method\b` matches every data set of that test but not other tests sharing the name prefix.
     */
    private static function filterFor(string $testName): string
    {
        [$class, $method] = str_contains($testName, '::') ? explode('::', $testName, 2) : ['', $testName];
        $short = substr($class, (int) strrpos('\\' . $class, '\\'));

        return escapeshellarg(($short !== '' ? $short . '::' : '') . $method . '\b');
    }
}
