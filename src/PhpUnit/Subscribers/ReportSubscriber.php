<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\PhpUnit\Subscribers;

use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use Axitdev\PhpUnitAiEvals\Support\Payload;
use DateTimeImmutable;
use PHPUnit\Event\Application\Finished;
use PHPUnit\Event\Application\FinishedSubscriber;

/**
 * Writes per-test eval metrics as JSON and, given a baseline report, prints judge scores and pass rates that dropped.
 *
 * @phpstan-import-type TestMetrics from TestContextRegistry
 *
 * @internal
 */
final class ReportSubscriber implements FinishedSubscriber
{
    public function __construct(
        private readonly ?string $reportPath,
        private readonly ?string $baselinePath,
        private readonly float $tolerance,
    ) {}

    public function notify(Finished $event): void
    {
        $tests = TestContextRegistry::tests();
        // Read the baseline first: it may be the same file the report is about to replace.
        $baseline = $this->baselinePath === null ? null : self::readBaseline($this->baselinePath);

        if ($this->reportPath !== null) {
            // PHPUnit drops exceptions thrown after the run, so a failed write is reported here instead.
            $error = $this->write($this->reportPath, $tests);
            if ($error !== null) {
                print "AI Evals: could not write the report: $error\n";
            }
        }

        if ($baseline !== null) {
            print self::renderRegressions($this->regressions($tests, $baseline));
            $unmatched = self::unmatched($tests, $baseline);
            if ($unmatched > 0) {
                print "AI Evals: {$unmatched} score(s) have no baseline entry and were not compared; update the baseline to include them.\n";
            }
        }
    }

    /**
     * @return array<string, mixed>|null The `tests` object, or null when there is nothing to compare against
     */
    private static function readBaseline(string $path): ?array
    {
        if (!is_file($path)) {
            print "AI Evals: baseline {$path} not found; copy a report there to start comparing.\n";

            return null;
        }
        $tests = Payload::get(json_decode((string) @file_get_contents($path), true), 'tests');
        if (!is_array($tests)) {
            print "AI Evals: baseline {$path} is not a report (no \"tests\" object); skipping the comparison.\n";

            return null;
        }

        return Payload::map($tests);
    }

    /**
     * @param array<string, TestMetrics> $tests
     * @return string|null The error, or null when the report was written
     */
    private function write(string $path, array $tests): ?string
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return sprintf('cannot create directory %s', $dir);
        }
        $json = json_encode([
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'totals' => TestContextRegistry::totals(),
            'tests' => $tests,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            return json_last_error_msg();
        }

        return @file_put_contents($path, $json . "\n") === false ? sprintf('cannot write %s', $path) : null;
    }

    /**
     * @param array<string, TestMetrics> $tests
     * @param array<string, mixed> $baseline The `tests` object of an earlier report
     * @return list<string>
     */
    public function regressions(array $tests, array $baseline): array
    {
        $lines = [];
        foreach ($tests as $test => $metrics) {
            $before = self::baselineScores($baseline, $test);
            foreach (self::currentScores($metrics) as $assertion => $score) {
                $old = $before[$assertion] ?? null;
                if ($old !== null && $score < $old - $this->tolerance) {
                    $lines[] = sprintf('  %s: %s %.2f -> %.2f', $test, $assertion, $old, $score);
                }
            }
        }

        return $lines;
    }

    /**
     * Scores of this run with nothing to compare against, e.g. after a new test or a renamed assertion.
     *
     * @param array<string, TestMetrics> $tests
     * @param array<string, mixed> $baseline
     */
    public static function unmatched(array $tests, array $baseline): int
    {
        $count = 0;
        foreach ($tests as $test => $metrics) {
            $count += count(array_diff_key(self::currentScores($metrics), self::baselineScores($baseline, $test)));
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $baseline
     * @return array<string, float>
     */
    private static function baselineScores(array $baseline, string $test): array
    {
        return self::scores(Payload::maps(Payload::get($baseline, $test, 'judge')), 'score')
            + self::scores(Payload::maps(Payload::get($baseline, $test, 'passRates')), 'rate');
    }

    /**
     * @param TestMetrics $metrics
     * @return array<string, float>
     */
    private static function currentScores(array $metrics): array
    {
        return self::scores($metrics['judge'], 'score') + self::scores($metrics['passRates'], 'rate');
    }

    /**
     * @param list<string> $lines
     */
    public static function renderRegressions(array $lines): string
    {
        return $lines === []
            ? "AI Evals: no regressions against the baseline.\n"
            : sprintf("AI Evals regressions against the baseline (%d):\n%s\n", count($lines), implode("\n", $lines));
    }

    /**
     * Average of $field per assertion, so repeated assertions in one test compare as one number.
     *
     * @param array<array<string, mixed>> $results
     * @return array<string, float>
     */
    private static function scores(array $results, string $field): array
    {
        $sums = [];
        foreach ($results as $result) {
            $sums[Payload::string($result['assertion'] ?? null)][] = Payload::float($result[$field] ?? null);
        }

        return array_map(static fn(array $values): float => array_sum($values) / count($values), $sums);
    }
}
