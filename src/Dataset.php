<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use InvalidArgumentException;
use SplFileObject;

/**
 * Loads eval cases from `.json` (a list or an object of cases), `.jsonl` (one case per line) or `.csv` (header row).
 * Cases are keyed by their `id` field, else by their key in a JSON object, else by position; duplicates are rejected.
 * A UTF-8 byte-order mark, as written by Excel and some editors, is ignored.
 */
final class Dataset
{
    /**
     * @return array<int|string, array<string, mixed>>
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Dataset file %s does not exist.', $path));
        }

        $cases = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'json' => self::json($path),
            'jsonl' => self::jsonLines($path),
            'csv' => self::csv($path),
            default => throw new InvalidArgumentException(sprintf('Unsupported dataset format %s; use .json, .jsonl or .csv.', $path)),
        };

        $keyed = [];
        foreach ($cases as $position => $case) {
            $id = $case['id'] ?? null;
            $key = is_string($id) || is_int($id) ? $id : $position;
            if (array_key_exists($key, $keyed)) {
                throw new InvalidArgumentException(sprintf('Dataset %s has more than one case with id "%s".', $path, $key));
            }
            $keyed[$key] = $case;
        }

        return $keyed;
    }

    /**
     * The same cases shaped for a PHPUnit data provider: each case is the single argument of the test.
     *
     * @return array<int|string, array{array<string, mixed>}>
     */
    public static function provide(string $path): array
    {
        return array_map(static fn(array $case): array => [$case], self::load($path));
    }

    private static function read(string $path): string
    {
        return self::withoutBom((string) file_get_contents($path));
    }

    private static function withoutBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }

    /**
     * @return array<array<string, mixed>>
     */
    private static function json(string $path): array
    {
        $decoded = json_decode(self::read($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException(sprintf('Dataset %s must hold a list or an object of cases.', $path));
        }

        return array_map(static fn(mixed $case): array => self::case($case, $path), $decoded);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function jsonLines(string $path): array
    {
        $cases = [];
        foreach (preg_split('/\R/', self::read($path)) ?: [] as $number => $line) {
            if (trim($line) !== '') {
                $cases[] = self::case(json_decode($line, true, 512, JSON_THROW_ON_ERROR), $path . ' line ' . ($number + 1));
            }
        }

        return $cases;
    }

    /**
     * @return array<string, mixed>
     */
    private static function case(mixed $case, string $where): array
    {
        if (!is_array($case) || ($case !== [] && array_is_list($case))) {
            throw new InvalidArgumentException(sprintf('Dataset %s: every case must be a JSON object, got %s.', $where, json_encode($case)));
        }

        return Payload::map($case);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function csv(string $path): array
    {
        $file = new SplFileObject($path);
        $file->setCsvControl(',', '"', '');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $header = null;
        $cases = [];
        foreach ($file as $row) {
            if (!is_array($row) || $row === [null]) {
                continue;
            }
            $row = array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $row);
            if ($header === null) {
                $header = $row;
                $header[0] = self::withoutBom($header[0] ?? '');
                if (count(array_unique($header)) !== count($header)) {
                    throw new InvalidArgumentException(sprintf('Dataset %s names a column more than once in its header.', $path));
                }
                continue;
            }
            if (count($row) > count($header)) {
                throw new InvalidArgumentException(sprintf('Dataset %s row %d has %d fields but the header has %d.', $path, $file->key() + 1, count($row), count($header)));
            }
            $cases[] = array_combine($header, array_pad($row, count($header), ''));
        }

        return $cases;
    }
}
