<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr\Exception;

use Axitdev\PhpUnitAiEvals\Vcr\Cassette;
use RuntimeException;

final class CassetteMissException extends RuntimeException
{
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
            $lines[] = '';
            $lines[] = "Diff against the closest recorded interaction (- recorded, + current):";
            $lines[] = self::diff($closest, $current);
        }
        $lines[] = '';
        $lines[] = 'Re-record with: AI_EVAL_MODE=record vendor/bin/phpunit --filter ' . self::filterFor($testName);

        return new self(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $recorded
     * @param array<string, mixed> $current
     */
    private static function diff(array $recorded, array $current): string
    {
        $old = Cassette::lines($recorded);
        $new = Cassette::lines($current);
        $out = [];
        foreach (array_diff($old, $new) as $line) {
            $out[] = '- ' . $line;
        }
        foreach (array_diff($new, $old) as $line) {
            $out[] = '+ ' . $line;
        }

        return $out === [] ? '(payloads differ only in line order)' : implode("\n", $out);
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
