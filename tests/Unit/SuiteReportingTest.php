<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Assertions\Constraints\MatchesRubricConstraint;
use Axitdev\PhpUnitAiEvals\PhpUnit\Subscribers\ExecutionFinishedSummarySubscriber;
use Axitdev\PhpUnitAiEvals\PhpUnit\Subscribers\ReportSubscriber;
use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use Axitdev\PhpUnitAiEvals\Tests\Support\SpyJudge;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class SuiteReportingTest extends TestCase
{
    #[Before]
    protected function freshRegistry(): void
    {
        TestContextRegistry::reset();
    }

    #[After]
    protected function leaveNoTrace(): void
    {
        TestContextRegistry::reset();
        AiTraceCollector::current()->clear();
    }

    private static function interaction(int $prompt, int $completion, int $reasoning = 0): AiInteraction
    {
        return new AiInteraction('openai', 'm', $prompt, $completion, $reasoning, 1.0, 'heavy body');
    }

    public function testCollectorFeedsAggregatesAndEndTestDropsHeavyTrace(): void
    {
        $collector = AiTraceCollector::current();
        TestContextRegistry::startTest('T::t');
        $collector->record(self::interaction(100, 30, 10), true);
        $collector->record(self::interaction(50, 20), false);

        self::assertCount(2, $collector->all());

        TestContextRegistry::endTest();

        self::assertSame([], $collector->all());
        $t = TestContextRegistry::totals();
        self::assertSame([2, 1, 1, 150, 50, 10], [$t['interactions'], $t['vcrHits'], $t['liveCalls'], $t['promptTokens'], $t['completionTokens'], $t['reasoningTokens']]);
    }

    public function testStartTestClearsLeftovers(): void
    {
        AiTraceCollector::current()->record(self::interaction(1, 1));
        TestContextRegistry::startTest('T::next');

        self::assertSame([], AiTraceCollector::current()->all());
    }

    public function testSummaryMatchesSpecFormat(): void
    {
        $collector = AiTraceCollector::current();
        $collector->record(self::interaction(3400, 700, 120), true);
        $collector->record(self::interaction(0, 110), false);
        TestContextRegistry::recordJudge('r', 1.0, 0.8, true, '');
        TestContextRegistry::recordJudge('r', 0.9, 0.8, true, '');

        self::assertSame(<<<'TXT'

            AI Evals Summary:
              Interactions: 2 total (1 VCR hits, 1 live calls)
              Tokens Used:  4,210 (Prompt: 3,400 / Completion: 810 / Reasoning: 120)
              Judge Evals:  2 passed (Avg Score: 0.95)


            TXT, ExecutionFinishedSummarySubscriber::render(TestContextRegistry::totals()));
    }

    public function testSummaryMentionsFailedJudgesAndIsEmptyWithoutActivity(): void
    {
        self::assertSame('', ExecutionFinishedSummarySubscriber::render(TestContextRegistry::totals()));

        TestContextRegistry::recordJudge('r', 0.2, 0.8, false, '');

        self::assertStringContainsString('0 passed, 1 failed (Avg Score: 0.20)', ExecutionFinishedSummarySubscriber::render(TestContextRegistry::totals()));
    }

    public function testJudgeSummaryCountsTheAssertionThreshold(): void
    {
        (new MatchesRubricConstraint(new SpyJudge(0.6), 'r', 0.8))->evaluate('x', '', true);

        $t = TestContextRegistry::totals();
        self::assertSame([0, 1], [$t['judgePassed'], $t['judgeFailed']]);
    }

    public function testMetricsAreKeptPerTest(): void
    {
        TestContextRegistry::startTest('A::a');
        AiTraceCollector::current()->record(self::interaction(10, 5), true);
        (new MatchesRubricConstraint(new SpyJudge(0.9, 'fine'), 'polite', 0.8))->evaluate('x', '', true);
        TestContextRegistry::endTest();
        TestContextRegistry::startTest('B::b');
        TestContextRegistry::recordPassRate('pass rate', 3, 4, 0.7);
        TestContextRegistry::endTest();
        AiTraceCollector::current()->record(self::interaction(1, 1));

        $tests = TestContextRegistry::tests();
        self::assertSame(['A::a', 'B::b', TestContextRegistry::UNKNOWN_TEST], array_keys($tests));
        self::assertSame([1, 15, 1.0], [$tests['A::a']['interactions'], $tests['A::a']['promptTokens'] + $tests['A::a']['completionTokens'], $tests['A::a']['latencyMs']]);
        self::assertSame(
            [['assertion' => 'satisfies the rubric "polite"', 'score' => 0.9, 'threshold' => 0.8, 'passed' => true, 'reason' => 'fine']],
            $tests['A::a']['judge'],
        );
        self::assertSame([['assertion' => 'pass rate', 'passed' => 3, 'total' => 4, 'rate' => 0.75, 'minRate' => 0.7]], $tests['B::b']['passRates']);
    }

    public function testRegressionsCompareAverageScoresPerAssertionWithTolerance(): void
    {
        TestContextRegistry::startTest('A::a');
        TestContextRegistry::recordJudge('rubric', 0.9, 0.5, true, '');
        TestContextRegistry::recordJudge('rubric', 0.7, 0.5, true, '');
        TestContextRegistry::recordJudge('grounded', 0.96, 0.5, true, '');
        TestContextRegistry::recordPassRate('pass rate', 7, 10, 0.5);
        TestContextRegistry::startTest('New::test');
        TestContextRegistry::recordJudge('rubric', 0.1, 0.0, true, '');

        $baseline = [
            'A::a' => [
                'judge' => [['assertion' => 'rubric', 'score' => 0.95], ['assertion' => 'grounded', 'score' => 1.0]],
                'passRates' => [['assertion' => 'pass rate', 'rate' => 0.9]],
            ],
        ];
        $lines = (new ReportSubscriber(null, null, 0.05))->regressions(TestContextRegistry::tests(), $baseline);

        // rubric avg 0.80 < 0.95 - 0.05; grounded 0.96 is within tolerance; tests missing from the baseline are skipped.
        self::assertSame(['  A::a: rubric 0.95 -> 0.80', '  A::a: pass rate 0.90 -> 0.70'], $lines);
        self::assertStringContainsString('regressions against the baseline (2)', ReportSubscriber::renderRegressions($lines));
        self::assertStringContainsString('no regressions', ReportSubscriber::renderRegressions([]));

        TestContextRegistry::reset();
        TestContextRegistry::startTest('A::a');
        TestContextRegistry::recordJudge('r', 1.0, 0.5, true, '');
        TestContextRegistry::recordJudge('new', 1.0, 0.5, true, '');
        TestContextRegistry::recordPassRate('p', 1, 1, 0.5);
        self::assertSame(
            2,
            ReportSubscriber::unmatched(TestContextRegistry::tests(), ['A::a' => ['judge' => [['assertion' => 'r', 'score' => 1.0]]]]),
            'a new judge assertion and a pass rate without baseline entries',
        );
    }

    public function testRegistryCountsHitsLatencyAndTotalsAcrossTests(): void
    {
        TestContextRegistry::startTest('A::a');
        AiTraceCollector::current()->record(new AiInteraction('openai', 'm', 10, 1, 0, 100.0, ''), true);
        AiTraceCollector::current()->record(new AiInteraction('openai', 'm', 10, 1, 0, 50.0, ''), true);
        AiTraceCollector::current()->record(new AiInteraction('openai', 'm', 10, 1, 0, 25.0, ''));
        TestContextRegistry::startTest('B::b');
        AiTraceCollector::current()->record(new AiInteraction('openai', 'm', 5, 1, 0, 1.0, ''));

        $tests = TestContextRegistry::tests();
        self::assertSame([2, 1, 175.0], [$tests['A::a']['vcrHits'], $tests['A::a']['liveCalls'], $tests['A::a']['latencyMs']], 'record() without a flag is a live call');
        self::assertSame([4, 35], [TestContextRegistry::totals()['interactions'], TestContextRegistry::totals()['promptTokens']]);
    }

    public function testRegistryEdgesPassRatesUsedKeysAndScrubber(): void
    {
        TestContextRegistry::recordPassRate('empty', 0, 0, 0.5);
        self::assertSame(0.0, TestContextRegistry::tests()[TestContextRegistry::UNKNOWN_TEST]['passRates'][0]['rate']);

        TestContextRegistry::markUsed('/c.json', 'k');
        self::assertSame(['/c.json' => ['k' => true]], TestContextRegistry::used());

        self::assertSame(TestContextRegistry::scrubber(), TestContextRegistry::scrubber());
    }

    public function testSummaryOmitsTheJudgeLineWithoutJudgesAndBaselineEdgesAreInclusive(): void
    {
        AiTraceCollector::current()->record(self::interaction(1, 1));
        self::assertStringNotContainsString('Judge Evals', ExecutionFinishedSummarySubscriber::render(TestContextRegistry::totals()));

        $tests = ['A::a' => ['interactions' => 0, 'vcrHits' => 0, 'liveCalls' => 0, 'promptTokens' => 0, 'completionTokens' => 0, 'reasoningTokens' => 0, 'latencyMs' => 0.0,
            'judge' => [['assertion' => 'r', 'score' => 0.75, 'threshold' => 0.5, 'passed' => true, 'reason' => '']], 'passRates' => []]];
        self::assertSame([], (new ReportSubscriber(null, null, 0.25))->regressions($tests, ['A::a' => ['judge' => [['assertion' => 'r', 'score' => 1.0]]]]), 'a drop of exactly the tolerance is fine');
    }
}
