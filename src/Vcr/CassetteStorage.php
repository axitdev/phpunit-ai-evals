<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr;

use JsonException;
use RuntimeException;

/**
 * @internal
 */
final class CassetteStorage
{
    public function __construct(private readonly string $directory) {}

    public function pathFor(string $name): string
    {
        $file = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name), '_');
        $base = str_ends_with($file, '.json') ? substr($file, 0, -5) : $file;
        // Filesystems cap a name at 255 bytes, and the temp file of an atomic write adds 13.
        if (strlen($base) > 200) {
            $base = substr($base, 0, 183) . '_' . substr(hash('xxh128', $base), 0, 16);
        }

        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $base . '.json';
    }

    /**
     * @throws JsonException
     */
    public function load(string $path): ?Cassette
    {
        $json = @file_get_contents($path);

        return $json === false ? null : Cassette::fromJson($json);
    }

    public function merge(string $path, Cassette $incoming): void
    {
        $this->update($path, static function (?Cassette $current) use ($incoming): Cassette {
            $merged = $current ?? new Cassette($incoming->test);
            $merged->merge($incoming);

            return $merged;
        });
    }

    /**
     * @param array<string, true> $keys
     */
    public function prune(string $path, array $keys): int
    {
        $removed = 0;
        $this->update($path, static function (?Cassette $current) use ($keys, &$removed): ?Cassette {
            $removed = $current?->retain($keys) ?? 0;

            return $removed > 0 ? $current : null;
        });

        return $removed;
    }

    /**
     * The read-modify-write cycle runs under an exclusive lock and the file is replaced via rename(), so parallel
     * workers and DataProvider runs never lose recordings.
     *
     * @param callable(?Cassette): ?Cassette $change returns the cassette to write, or null to leave the file as is
     */
    private function update(string $path, callable $change): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create cassette directory %s.', $dir));
        }

        $lockPath = self::lockPath($path);
        self::ensurePrivateDir(dirname($lockPath));
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException(sprintf('Cannot lock cassette %s.', $path));
        }

        try {
            $cassette = $change($this->load($path));
            if ($cassette === null) {
                return;
            }

            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, $cassette->toJson()) === false || !rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException(sprintf('Cannot write cassette %s.', $path));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Per-user temp folder: nothing appears next to the cassettes, and other users can't pre-create the lock. */
    public static function lockPath(string $path): string
    {
        $real = (realpath(dirname($path)) ?: dirname($path)) . DIRECTORY_SEPARATOR . basename($path);
        $user = function_exists('posix_geteuid') ? (string) posix_geteuid() : ''; // Windows temp dirs are per user already

        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpunit-ai-evals-' . $user . DIRECTORY_SEPARATOR . hash('xxh128', $real) . '.lock';
    }

    private static function ensurePrivateDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o700) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create lock directory %s.', $dir));
        }
        if (function_exists('posix_geteuid') && fileowner($dir) !== posix_geteuid()) {
            throw new RuntimeException(sprintf('Lock directory %s belongs to another user; remove it.', $dir));
        }
    }
}
