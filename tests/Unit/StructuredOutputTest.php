<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class StructuredOutputTest extends TestCase
{
    use AiAssertionsTrait;

    private const ANSWER = '{"label": "refund", "scores": {"relevance": 0.82, "toxicity": 0}, "items": [{"score": 3}], "sku": "1"}';

    public function testScoreAssertionsPassInsideTheirBounds(): void
    {
        self::assertScoreAtLeast('scores.relevance', 0.8, self::ANSWER);
        self::assertScoreAtLeast('scores.toxicity', 0.0, self::ANSWER);
        self::assertScoreBetween('items.0.score', 1, 5, self::ANSWER);
        self::assertScoreBetween('/scores/relevance', 0.82, 0.82, self::ANSWER);
        self::assertFieldIn('label', ['refund', 'exchange'], self::ANSWER);
    }

    public function testScoreBelowTheMinimumNamesFieldAndValue(): void
    {
        $this->assertFailsWith(
            static fn() => self::assertScoreAtLeast('scores.relevance', 0.9, self::ANSWER),
            'Field `scores.relevance` of the model output is 0.82, below the minimum of 0.9.',
        );
    }

    public function testScoreOutsideTheRange(): void
    {
        $this->assertFailsWith(
            static fn() => self::assertScoreBetween('items.0.score', 4, 5, self::ANSWER, 'quality gate'),
            "quality gate\nField `items.0.score` of the model output is 3, outside [4, 5].",
        );
    }

    public function testEnumMembershipIsStrict(): void
    {
        $this->assertFailsWith(
            static fn() => self::assertFieldIn('sku', [1, 2], self::ANSWER),
            'Field `sku` of the model output is "1", which is not one of: 1, 2.',
        );
    }

    public function testMissingFieldListsWhatIsThere(): void
    {
        $this->assertFailsWith(
            static fn() => self::assertScoreAtLeast('scores.helpfulness', 0.5, self::ANSWER),
            'Field `scores.helpfulness` is missing from the model output: `scores` has no `helpfulness` (it has: relevance, toxicity).',
        );
    }

    public function testNumericStringIsNotANumber(): void
    {
        $this->assertFailsWith(
            static fn() => self::assertScoreAtLeast('score', 0.5, '{"score": "0.9"}'),
            'Field `score` of the model output is not a number: ""0.9"" (a numeric string; ask for a JSON number',
        );
    }

    public function testInvalidJsonExplainsCommonCauses(): void
    {
        $this->assertFailsWith(
            static fn() => self::assertScoreAtLeast('score', 0.5, "```json\n{\"score\": 1}\n```"),
            'The model output is not valid JSON (Syntax error). It is wrapped in a Markdown code fence',
        );
        $this->assertFailsWith(
            static fn() => self::assertFieldIn('label', ['a'], 'Sure! Here it is: {"label": "a"}'),
            'There is text around the JSON',
        );
        $this->assertFailsWith(
            static fn() => self::assertMatchesJsonSchema(['type' => 'object'], ''),
            'The output is empty',
        );
    }

    public function testReadsTheLastInteractionOrAGivenOne(): void
    {
        $interaction = new AiInteraction('openai', 'm', 1, 1, 0, 1.0, '{"verdict": "pass", "confidence": 0.95}');
        AiTraceCollector::current()->record($interaction);

        self::assertMatchesJsonSchema(['type' => 'object', 'required' => ['verdict', 'confidence']]);
        self::assertScoreAtLeast('confidence', 0.9);
        self::assertFieldIn('verdict', ['pass', 'fail'], $interaction);
    }

    public function testWithoutAnyInteractionTheFailureSaysSo(): void
    {
        $this->assertFailsWith(static fn() => self::assertScoreAtLeast('x', 1), 'No AI interaction was recorded in this test');
    }

    public function testSchemaMismatchOnAnInteraction(): void
    {
        $interaction = new AiInteraction('openai', 'm', 1, 1, 0, 1.0, '{"verdict": 3}');

        $this->assertFailsWith(
            static fn() => self::assertMatchesJsonSchema(['type' => 'object', 'properties' => ['verdict' => ['type' => 'string']]], $interaction),
            'matches the JSON schema',
        );
    }

    private function assertFailsWith(callable $assertion, string $expected): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString($expected, $e->getMessage());

            return;
        }
        self::fail('Expected the assertion to fail.');
    }
}
