<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr;

/**
 * Counts how often each (kind, semantic hash) has been sent to one cassette during one test, so repeated identical
 * requests map to the 1st, 2nd and Nth recording. Every client writing to that cassette in the test must share it.
 *
 * @internal
 */
final class OccurrenceCounter
{
    /** @var array<string, int> */
    private array $seen = [];

    public function next(string $kind, string $hash): int
    {
        $key = $kind . ':' . $hash;
        $this->seen[$key] = ($this->seen[$key] ?? -1) + 1;

        return $this->seen[$key];
    }

    /** Undoes next() for a request that wasn't recorded, so the successful retry lands where a replay looks for it. */
    public function release(string $kind, string $hash): void
    {
        $key = $kind . ':' . $hash;
        if (($this->seen[$key] ?? -1) >= 0) {
            $this->seen[$key]--;
        }
    }
}
