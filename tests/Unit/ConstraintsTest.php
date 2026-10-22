<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Assertions\Constraints\MatchesJsonSchemaConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\MatchesRubricConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\NoHallucinationConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\ToolCalledConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\ToolCallOrderConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\TraceBudgetConstraint;
use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use Axitdev\PhpUnitAiEvals\Tests\Support\SpyJudge;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\ToolCall;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConstraintsTest extends TestCase
{
    /**
     * @param list<ToolCall> $calls
     */
    private static function interaction(array $calls = [], int $prompt = 10, int $completion = 5, float $latency = 0.0): AiInteraction
    {
        return new AiInteraction('openai', 'm', $prompt, $completion, 0, $latency, '', $calls);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool}>
     */
    public static function subsets(): iterable
    {
        yield 'exact' => [['order_id' => 404], ['order_id' => 404], true];
        yield 'extra keys ignored' => [['order_id' => 404], ['order_id' => 404, 'reason' => 'x'], true];
        yield 'missing key' => [['order_id' => 404], ['id' => 404], false];
        yield 'wrong value' => [['order_id' => 404], ['order_id' => 405], false];
        yield 'int vs float' => [['amount' => 10], ['amount' => 10.0], true];
        yield 'string is not number' => [['order_id' => 404], ['order_id' => '404'], false];
        yield 'nested subset' => [['a' => ['b' => ['c' => 1]]], ['a' => ['x' => 0, 'b' => ['c' => 1, 'd' => 2]]], true];
        yield 'nested mismatch' => [['a' => ['b' => 1]], ['a' => ['b' => 2]], false];
        yield 'array vs scalar' => [['a' => ['b' => 1]], ['a' => 5], false];
        yield 'list any-order' => [['items' => [2, 1]], ['items' => [1, 2, 3]], true];
        yield 'list missing element' => [['items' => [4]], ['items' => [1, 2, 3]], false];
        yield 'list of maps' => [['items' => [['sku' => 'a']]], ['items' => [['sku' => 'b'], ['sku' => 'a', 'qty' => 2]]], true];
        yield 'empty expected' => [[], ['anything' => 1], true];
        yield 'null value' => [['a' => null], ['a' => null], true];
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    #[DataProvider('subsets')]
    public function testSubsetMatching(array $expected, array $actual, bool $result): void
    {
        self::assertSame($result, ToolCalledConstraint::isSubset($expected, $actual));
    }

    public function testToolCalledAcrossMultipleInteractions(): void
    {
        $trace = [
            self::interaction([new ToolCall('lookup_order', ['id' => 1])]),
            self::interaction([new ToolCall('issue_refund', ['order_id' => 404, 'note' => 'x'])]),
        ];

        self::assertThat($trace, new ToolCalledConstraint('issue_refund'));
        self::assertThat($trace, new ToolCalledConstraint('issue_refund', ['order_id' => 404]));
        self::assertThat($trace, self::logicalNot(new ToolCalledConstraint('issue_refund', ['order_id' => 1]))); // wrong args
        self::assertThat($trace, self::logicalNot(new ToolCalledConstraint('delete_account')));
    }

    public function testToolCalledFailureMessageListsActualCalls(): void
    {
        try {
            self::assertThat([self::interaction([new ToolCall('a', ['x' => 1])])], new ToolCalledConstraint('b', ['y' => 2]));
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('called tool "b" with arguments containing {"y":2}', $e->getMessage());
            self::assertStringContainsString('calls made: a({"x":1})', $e->getMessage());
        }
        try {
            self::assertThat([], new ToolCalledConstraint('b'));
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('no tool calls were made', $e->getMessage());
        }
    }

    public function testToolCalledIgnoresNonInteractionItems(): void
    {
        self::assertThat(['junk', self::interaction([new ToolCall('t')])], new ToolCalledConstraint('t'));
        self::assertThat('not an array', self::logicalNot(new ToolCalledConstraint('t')));
    }

    public function testTokenBudgetSumsAcrossInteractions(): void
    {
        $trace = [self::interaction(prompt: 100, completion: 20), self::interaction(prompt: 50, completion: 30)];

        self::assertThat($trace, TraceBudgetConstraint::tokens(201));
        self::assertThat($trace, self::logicalNot(TraceBudgetConstraint::tokens(200)));
        self::assertThat([], TraceBudgetConstraint::tokens(1));

        try {
            self::assertThat($trace, TraceBudgetConstraint::tokens(150));
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('200 total tokens', $e->getMessage());
            self::assertStringContainsString('fewer than 150', $e->getMessage());
        }
    }

    public function testNoHallucinationThresholdAndPromptContents(): void
    {
        $judge = new SpyJudge(0.9);

        self::assertThat('Refund done.', new NoHallucinationConstraint($judge, ['Order 404 refunded'], 0.85));
        self::assertThat('Refund done.', self::logicalNot(new NoHallucinationConstraint(new SpyJudge(0.8), ['ctx'], 0.85)));
        self::assertStringContainsString('Order 404 refunded', $judge->user);
        self::assertStringContainsString('Refund done.', $judge->user);
    }

    public function testJudgeResultsAreReportedWithoutTheThresholdSoChangingItKeepsTheBaseline(): void
    {
        TestContextRegistry::reset();
        TestContextRegistry::startTest('T::t');
        $judge = new SpyJudge(0.9);
        (new MatchesRubricConstraint($judge, 'be polite', 0.5))->evaluate('hi', '', true);
        (new NoHallucinationConstraint($judge, ['ctx'], 0.7))->evaluate('hi', '', true);

        self::assertSame(
            ['satisfies the rubric "be polite"', 'is grounded in the given context'],
            array_column(TestContextRegistry::tests()['T::t']['judge'], 'assertion'),
        );
        TestContextRegistry::reset();
    }

    public function testJudgeFailureMessageCarriesScoreAndReason(): void
    {
        try {
            self::assertThat('out', new MatchesRubricConstraint(new SpyJudge(0.4, 'too curt'), 'be polite', 0.8));
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('satisfies the rubric "be polite"', $e->getMessage());
            self::assertStringContainsString('Judge score 0.40 (threshold 0.80): too curt', $e->getMessage());
        }
    }

    public function testRubricPassesAtExactThreshold(): void
    {
        self::assertThat('out', new MatchesRubricConstraint(new SpyJudge(0.8), 'r', 0.8));
    }

    public function testToolCalledTimesCountsMatchingCalls(): void
    {
        $trace = [
            self::interaction([new ToolCall('search', ['q' => 'a']), new ToolCall('search', ['q' => 'b'])]),
            self::interaction([new ToolCall('search', ['q' => 'a'])]),
        ];

        self::assertThat($trace, new ToolCalledConstraint('search', null, 3));
        self::assertThat($trace, new ToolCalledConstraint('search', ['q' => 'a'], 2));
        self::assertThat($trace, new ToolCalledConstraint('refund', null, 0));
        self::assertThat($trace, self::logicalNot(new ToolCalledConstraint('search', null, 2)));
        self::assertSame('called tool "x" exactly 1 time', (new ToolCalledConstraint('x', null, 1))->toString());
    }

    public function testToolCallOrderAllowsInterleavedCalls(): void
    {
        $trace = [
            self::interaction([new ToolCall('lookup'), new ToolCall('log')]),
            self::interaction([new ToolCall('refund'), new ToolCall('notify')]),
        ];

        self::assertThat($trace, new ToolCallOrderConstraint(['lookup', 'refund', 'notify']));
        self::assertThat($trace, new ToolCallOrderConstraint([]));
        self::assertThat($trace, self::logicalNot(new ToolCallOrderConstraint(['refund', 'lookup'])));
        self::assertThat($trace, self::logicalNot(new ToolCallOrderConstraint(['lookup', 'lookup'])));

        try {
            self::assertThat($trace, new ToolCallOrderConstraint(['refund', 'lookup']));
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('called tools in order refund -> lookup (actual order: lookup -> log -> refund -> notify)', $e->getMessage());
        }
    }

    public function testLatencyBudgetSumsAcrossInteractions(): void
    {
        $trace = [self::interaction(latency: 120.5), self::interaction(latency: 80.0)];

        self::assertThat($trace, TraceBudgetConstraint::latency(201.0));
        try {
            self::assertThat($trace, TraceBudgetConstraint::latency(150));
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('(200.5 ms of model latency) uses fewer than 150.0 ms of model latency', $e->getMessage());
        }
    }

    public function testJsonSchemaValidatesStructuredOutput(): void
    {
        $schema = ['type' => 'object', 'required' => ['sentiment'], 'properties' => ['sentiment' => ['enum' => ['positive', 'negative']]]];

        self::assertThat('{"sentiment":"positive"}', new MatchesJsonSchemaConstraint($schema));
        self::assertThat('{"sentiment":"positive"}', new MatchesJsonSchemaConstraint((string) json_encode($schema)));

        try {
            self::assertThat('{"sentiment":"meh"}', new MatchesJsonSchemaConstraint($schema));
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('the output matches the JSON schema', $e->getMessage());
            self::assertStringContainsString('/sentiment', $e->getMessage());
        }
        try {
            self::assertThat('not json', new MatchesJsonSchemaConstraint($schema));
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Not valid JSON', $e->getMessage());
        }
    }

    public function testFailureMessagesHaveAnExactWording(): void
    {
        $trace = [self::interaction([new ToolCall('lookup', ['city' => 'Zürich/CH'])])];
        $message = static function (callable $assertion): string {
            try {
                $assertion();
            } catch (AssertionFailedError $e) {
                return $e->getMessage();
            }
            self::fail('expected a failure');
        };

        self::assertSame(
            'Failed asserting that the agent trace called tool "refund" with arguments containing {"note":"café/ok"} (calls made: lookup({"city":"Zürich/CH"})).',
            $message(static fn() => self::assertThat($trace, new ToolCalledConstraint('refund', ['note' => 'café/ok']))),
        );
        self::assertSame(
            'Failed asserting that the agent trace called tools in order refund -> lookup (actual order: lookup).',
            $message(static fn() => self::assertThat($trace, new ToolCallOrderConstraint(['refund', 'lookup']))),
        );
        self::assertStringStartsWith(
            "Failed asserting that the output is grounded in the given context (hallucination score >= 0.85).\nJudge score 0.10",
            $message(static fn() => self::assertThat('x', new NoHallucinationConstraint(new SpyJudge(0.1), ['ctx'], 0.85))),
        );
        self::assertStringContainsString(
            'Not valid JSON: Syntax error',
            $message(static fn() => self::assertThat('{oops', new MatchesJsonSchemaConstraint(['type' => 'object']))),
        );
        self::assertStringContainsString(
            '"/sentiment"',
            $message(static fn() => self::assertThat('{"sentiment":1}', new MatchesJsonSchemaConstraint(['properties' => ['sentiment' => ['type' => 'string']]]))),
            'error paths are printed without escaped slashes',
        );
    }

    public function testSubsetMatchingNeedsADistinctItemPerExpectedItem(): void
    {
        self::assertFalse(ToolCalledConstraint::isSubset(['items' => [1, 1]], ['items' => [1]]), 'two expected items need two actual ones');
        self::assertTrue(ToolCalledConstraint::isSubset(['items' => [1, 1]], ['items' => [1, 2, 1]]));
        self::assertTrue(ToolCalledConstraint::isSubset(['items' => [[], [1]]], ['items' => [[1], []]]), 'items are paired in any order');
        self::assertFalse(ToolCalledConstraint::isSubset(['tags' => []], ['tags' => ['a']]), 'an empty array only matches an empty one');
        self::assertTrue(ToolCalledConstraint::isSubset(['tags' => []], ['tags' => []]));
        self::assertFalse(ToolCalledConstraint::isSubset(['meta' => []], ['meta' => ['k' => 1]]));
        self::assertFalse(ToolCalledConstraint::isSubset(['a' => 1], ['a' => true]), 'true is not 1');
        self::assertFalse(ToolCalledConstraint::isSubset(['a' => 1], ['a' => '1']), 'a numeric string is not a number');
    }
}
