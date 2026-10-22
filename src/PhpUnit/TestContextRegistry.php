<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\PhpUnit;

use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use Axitdev\PhpUnitAiEvals\Vcr\SecretScrubber;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;

/**
 * Process-wide state shared by the extension, its subscribers and the assertions trait: extension settings,
 * lightweight per-test metrics (never request/response bodies) and the cassette entries used this run.
 *
 * @phpstan-type Totals array{
 *     interactions: int, vcrHits: int, liveCalls: int,
 *     promptTokens: int, completionTokens: int, reasoningTokens: int,
 *     judgePassed: int, judgeFailed: int, judgeScoreSum: float
 * }
 * @phpstan-type JudgeResult array{assertion: string, score: float, threshold: float, passed: bool, reason: string}
 * @phpstan-type PassRateResult array{assertion: string, passed: int, total: int, rate: float, minRate: float}
 * @phpstan-type TestMetrics array{
 *     interactions: int, vcrHits: int, liveCalls: int,
 *     promptTokens: int, completionTokens: int, reasoningTokens: int, latencyMs: float,
 *     judge: list<JudgeResult>, passRates: list<PassRateResult>
 * }
 *
 * @internal
 */
final class TestContextRegistry
{
    /** Bucket for metrics recorded outside a test the extension announced (e.g. without the extension). */
    public const UNKNOWN_TEST = '(unknown)';

    private const EMPTY_METRICS = [
        'interactions' => 0, 'vcrHits' => 0, 'liveCalls' => 0,
        'promptTokens' => 0, 'completionTokens' => 0, 'reasoningTokens' => 0, 'latencyMs' => 0.0,
        'judge' => [], 'passRates' => [],
    ];

    private static ?string $cassettesPath = null;

    private static ?VcrMode $mode = null;

    private static ?SecretScrubber $scrubber = null;

    private static ?string $currentTest = null;

    /** @var array<string, TestMetrics> */
    private static array $tests = [];

    /** @var array<string, array<string, true>> cassette path => set of "kind:hash:occurrence" */
    private static array $used = [];

    public static function configure(?string $cassettesPath, ?VcrMode $mode, ?SecretScrubber $scrubber = null): void
    {
        self::$cassettesPath = $cassettesPath;
        self::$mode = $mode;
        self::$scrubber = $scrubber;
    }

    public static function scrubber(): SecretScrubber
    {
        return self::$scrubber ??= new SecretScrubber();
    }

    public static function cassettesPath(): ?string
    {
        return self::$cassettesPath;
    }

    public static function configuredMode(): ?VcrMode
    {
        return self::$mode;
    }

    public static function startTest(string $testId): void
    {
        self::$currentTest = $testId;
        AiTraceCollector::current()->clear();
    }

    /**
     * Drops the heavy per-test trace; only the numeric metrics survive.
     */
    public static function endTest(): void
    {
        self::$currentTest = null;
        AiTraceCollector::current()->clear();
    }

    public static function recordInteraction(AiInteraction $interaction, bool $replayed): void
    {
        $m = &self::metrics();
        $m['interactions']++;
        $m[$replayed ? 'vcrHits' : 'liveCalls']++;
        $m['promptTokens'] += $interaction->promptTokens;
        $m['completionTokens'] += $interaction->completionTokens;
        $m['reasoningTokens'] += $interaction->reasoningTokens;
        $m['latencyMs'] += $interaction->latencyMs;
    }

    /**
     * @param string $assertion What was asserted, e.g. the constraint's description
     * @param bool $passed Whether the score met the assertion's threshold
     */
    public static function recordJudge(string $assertion, float $score, float $threshold, bool $passed, string $reason): void
    {
        self::metrics()['judge'][] = ['assertion' => $assertion, 'score' => $score, 'threshold' => $threshold, 'passed' => $passed, 'reason' => $reason];
    }

    public static function recordPassRate(string $assertion, int $passed, int $total, float $minRate): void
    {
        $rate = $total === 0 ? 0.0 : $passed / $total;
        self::metrics()['passRates'][] = ['assertion' => $assertion, 'passed' => $passed, 'total' => $total, 'rate' => $rate, 'minRate' => $minRate];
    }

    public static function markUsed(string $cassettePath, string $key): void
    {
        self::$used[$cassettePath][$key] = true;
    }

    /**
     * @return array<string, array<string, true>>
     */
    public static function used(): array
    {
        return self::$used;
    }

    /**
     * @return array<string, TestMetrics>
     */
    public static function tests(): array
    {
        return self::$tests;
    }

    /**
     * @return Totals
     */
    public static function totals(): array
    {
        $t = [
            'interactions' => 0, 'vcrHits' => 0, 'liveCalls' => 0,
            'promptTokens' => 0, 'completionTokens' => 0, 'reasoningTokens' => 0,
            'judgePassed' => 0, 'judgeFailed' => 0, 'judgeScoreSum' => 0.0,
        ];
        foreach (self::$tests as $m) {
            foreach (['interactions', 'vcrHits', 'liveCalls', 'promptTokens', 'completionTokens', 'reasoningTokens'] as $key) {
                $t[$key] += $m[$key];
            }
            foreach ($m['judge'] as $judge) {
                $t[$judge['passed'] ? 'judgePassed' : 'judgeFailed']++;
                $t['judgeScoreSum'] += $judge['score'];
            }
        }

        return $t;
    }

    public static function reset(): void
    {
        self::$cassettesPath = null;
        self::$mode = null;
        self::$scrubber = null;
        self::$currentTest = null;
        self::$tests = [];
        self::$used = [];
    }

    /**
     * @return TestMetrics
     */
    private static function &metrics(): array
    {
        self::$tests[self::$currentTest ?? self::UNKNOWN_TEST] ??= self::EMPTY_METRICS;

        return self::$tests[self::$currentTest ?? self::UNKNOWN_TEST];
    }
}
