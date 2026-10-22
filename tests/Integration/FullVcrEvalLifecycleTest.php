<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Integration;

use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use Axitdev\PhpUnitAiEvals\Attributes\AiJudgeConfig;
use Axitdev\PhpUnitAiEvals\Attributes\AiVcr;
use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\SpyJudge;
use Axitdev\PhpUnitAiEvals\Tests\Support\TempDirTrait;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use Axitdev\PhpUnitAiEvals\Trace\Parser\ProviderPayloadParserInterface;
use Axitdev\PhpUnitAiEvals\Trace\ToolCall;
use Axitdev\PhpUnitAiEvals\Vcr\AiVcrClient;
use Axitdev\PhpUnitAiEvals\Vcr\Exception\CassetteMissException;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\IncompleteTest;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

#[AiJudgeConfig(model: 'judge-mini', baseUri: 'http://judge.test/v1')]
final class FullVcrEvalLifecycleTest extends TestCase
{
    use AiAssertionsTrait;
    use TempDirTrait;

    private const LLM = 'https://api.openai.com/v1/chat/completions';

    private const VARS = ['AI_EVAL_CASSETTES_PATH', 'AI_EVAL_MODE', 'CI', 'GITHUB_ACTIONS'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    /** @var list<ProviderPayloadParserInterface> */
    private array $extraParsers = [];

    #[Before(10)]
    protected function isolateEnv(): void
    {
        foreach (self::VARS as $var) {
            $this->savedEnv[$var] = getenv($var);
            putenv($var);
        }
    }

    #[After]
    protected function restoreEnv(): void
    {
        foreach ($this->savedEnv as $var => $value) {
            putenv($value === false ? $var : "$var=$value");
        }
    }

    protected function aiCassettesPath(): string
    {
        return $this->tmp;
    }

    /**
     * @return list<ProviderPayloadParserInterface>
     */
    protected function aiPayloadParsers(): array
    {
        return [...$this->extraParsers, ...AiVcrClient::defaultParsers()];
    }

    private function runAgent(ClientInterface $client): string
    {
        $messages = [['role' => 'user', 'content' => 'Refund order #404 (today is 2026-10-22)']];
        for ($turn = 0; $turn < 3; $turn++) {
            $response = $client->sendRequest(new Request('POST', self::LLM, ['Authorization' => 'Bearer sk-live'], json_encode([
                'model' => 'gpt-6-luna',
                'messages' => $messages,
                'temperature' => 0.7,
            ], JSON_THROW_ON_ERROR)));
            $message = Payload::map(Payload::get(json_decode((string) $response->getBody(), true), 'choices', 0, 'message'));
            if (($message['tool_calls'] ?? null) === []) {
                return Payload::string($message['content'] ?? null);
            }
            $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => $message['tool_calls'] ?? []];
            $messages[] = ['role' => 'tool', 'content' => '{"status":"refunded"}'];
        }

        self::fail('agent did not finish');
    }

    private function liveBackend(): FakeClient
    {
        return new FakeClient(
            FakeClient::json(FakeClient::openAiReply('', [['issue_refund', ['reason' => 'damaged', 'order_id' => 404]]], 100, 20)),
            FakeClient::json(FakeClient::openAiReply('Order 404 has been refunded.', [], 140, 12)),
            // Judge verdicts (consumed on the record pass only):
            FakeClient::json(FakeClient::openAiReply('{"score": 0.95, "reason": "grounded"}')),
            FakeClient::json(FakeClient::openAiReply("```json\n{\"score\": 0.9, \"reason\": \"polite\"}\n```")),
        );
    }

    private function evaluate(string $answer): void
    {
        self::assertToolCalled('issue_refund', ['order_id' => 404]);
        self::assertToolNotCalled('delete_account');
        self::assertTokenUsageLessThan(500);
        self::assertCount(2, $this->getAllAiInteractions());
        self::assertSame(152, $this->getLastAiInteraction()->totalTokens);
        $this->assertNoHallucination(['Order 404 was refunded'], $answer);
        $this->assertMatchesRubric('Confirms the refund politely', $answer);
    }

    public function testRecordThenReplayRunsOfflineAndDeterministically(): void
    {
        putenv('AI_EVAL_MODE=record');
        $backend = $this->liveBackend();
        $answer = $this->runAgent($this->wrapHttpClient($backend));
        $this->evaluate($answer);
        self::assertCount(4, $backend->requests);

        // Fresh state, strict replay, a backend that fails on any call.
        $this->resetAiEvalState();
        putenv('AI_EVAL_MODE=replay');
        $offline = new FakeClient();
        $replayed = $this->runAgent($this->wrapHttpClient($offline));
        $this->evaluate($replayed);

        self::assertSame($answer, $replayed);
        self::assertSame([], $offline->requests);
    }

    public function testCassetteFileHoldsAgentCallsAndJudgeVerdicts(): void
    {
        putenv('AI_EVAL_MODE=record');
        $answer = $this->runAgent($this->wrapHttpClient($this->liveBackend()));
        $this->assertMatchesRubric('Confirms the refund politely', $answer);

        $files = glob($this->tmp . '/*.json') ?: [];
        self::assertSame([$this->tmp . '/Axitdev_PhpUnitAiEvals_Tests_Integration_FullVcrEvalLifecycleTest_testCassetteFileHoldsAgentCallsAndJudgeVerdicts.json'], $files, 'one cassette per test, named after class and method');
        $json = (string) file_get_contents($files[0]);
        self::assertSame(2, substr_count($json, '"kind": "agent_call"'));
        self::assertSame(1, substr_count($json, '"kind": "judge_eval"'));
        self::assertStringNotContainsString('sk-live', $json);
        self::assertStringContainsString('<TIMESTAMP>', $json);
    }

    public function testStrictReplayOnCiFailsWithActionableMiss(): void
    {
        putenv('CI=true');
        $client = $this->wrapHttpClient(new FakeClient());

        $this->expectException(CassetteMissException::class);
        $this->expectExceptionMessage('AI_EVAL_MODE=record vendor/bin/phpunit --filter');
        $this->runAgent($client);
    }

    #[AiVcr(mode: VcrMode::Live)]
    public function testAttributeSelectsLiveModeAndSkipsCassette(): void
    {
        $this->runAgent($this->wrapHttpClient($this->liveBackend()));

        self::assertSame([], glob($this->tmp . '/*.json'));
    }

    #[AiVcr(cassette: 'shared-cassette', ignorePatterns: ['/#\d+/'])]
    public function testCustomCassetteNameAndIgnorePatterns(): void
    {
        putenv('AI_EVAL_MODE=record');
        $this->runAgent($this->wrapHttpClient($this->liveBackend()));

        self::assertFileExists($this->tmp . '/shared-cassette.json');
        self::assertStringContainsString('<IGNORED>', (string) file_get_contents($this->tmp . '/shared-cassette.json'));
    }

    #[AiVcr(cassette: 'named-only')]
    public function testAttributeWithoutModeKeepsExtensionMode(): void
    {
        TestContextRegistry::configure(null, VcrMode::Replay);
        try {
            $this->expectException(CassetteMissException::class);
            $this->runAgent($this->wrapHttpClient($this->liveBackend()));
        } finally {
            TestContextRegistry::configure(null, null);
        }
    }

    public function testAssertSampledIsolatesEachAttemptsTrace(): void
    {
        try {
            $this->assertSampled(2, 2, static function (int $attempt): void {
                if ($attempt === 1) {
                    AiTraceCollector::current()->record(new AiInteraction('openai', 'm', 1, 1, 0, 0.0, '', [new ToolCall('issue_refund')]));
                }
                self::assertToolCalled('issue_refund');
            });
            self::fail('attempt 2 must not see the tool call from attempt 1');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Only 1 of 2 sampled runs passed', $e->getMessage());
        }
    }

    public function testCustomParserFromOverrideIsUsed(): void
    {
        $this->extraParsers = [new class implements ProviderPayloadParserInterface {
            public function name(): string
            {
                return 'acme';
            }

            public function supports(string $uri): bool
            {
                return str_ends_with($uri, '/acme/complete');
            }

            public function requestPayload(string $uri, array $payload): array
            {
                return $payload;
            }

            public function parse(array $request, array $response, float $latencyMs): AiInteraction
            {
                return new AiInteraction('acme', 'a-1', 1, 2, 0, $latencyMs, Payload::string($response['text'] ?? null), [new ToolCall('acme_tool')]);
            }
        }];
        putenv('AI_EVAL_MODE=record');

        $this->wrapHttpClient(new FakeClient(FakeClient::json(['text' => 'hi'])))
            ->sendRequest(FakeClient::request('https://acme.test/acme/complete', ['model' => 'acme-1', 'prompt' => 'x']));

        self::assertSame(['acme', 'hi'], [$this->getLastAiInteraction()->provider, $this->getLastAiInteraction()->assistantContent]);
        self::assertToolCalled('acme_tool');
    }

    public function testAssertPassRateEvaluatesEveryCase(): void
    {
        $seen = [];
        $this->assertPassRate(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4], 0.75, function (int $n, string $key) use (&$seen): void {
            $seen[] = $key;
            self::assertNotSame(4, $n, 'four is unlucky');
        });
        self::assertSame(['a', 'b', 'c', 'd'], $seen);

        try {
            $this->assertPassRate([1, 2, 2], 0.9, static fn(int $n) => self::assertSame(1, $n), 'smoke');
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Only 1 of 3 cases passed (33%, need 90%)', $e->getMessage());
            self::assertStringContainsString('#1:', $e->getMessage());
        }
    }

    public function testSkippingInsideACaseSkipsTheTestInsteadOfFailingTheCase(): void
    {
        foreach ([fn() => $this->markTestSkipped('no key'), fn() => $this->markTestIncomplete('later')] as $mark) {
            try {
                $this->assertPassRate([1], 1.0, $mark);
                self::fail('expected the skip to escape');
            } catch (SkippedTest|IncompleteTest) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNewTraceAssertionsOnARecordedAgentRun(): void
    {
        putenv('AI_EVAL_MODE=record');
        $this->runAgent($this->wrapHttpClient($this->liveBackend()));

        self::assertToolCalledTimes('issue_refund', 1, ['order_id' => 404]);
        self::assertToolCallOrder(['issue_refund']);
        self::assertToolCalled('issue_refund', null, $this->getAllAiInteractions());
        self::assertLatencyLessThan(60_000);
        self::assertMatchesJsonSchema(['type' => 'object', 'required' => ['status']], '{"status":"refunded"}');
    }

    public function testTwoWrappedClientsInOneTestShareRepeatCounting(): void
    {
        putenv('AI_EVAL_MODE=record');
        $request = static fn(): Request => new Request('POST', self::LLM, [], (string) json_encode(['model' => 'gpt-6-luna', 'messages' => [['role' => 'user', 'content' => 'same']]]));
        $this->wrapHttpClient(new FakeClient(FakeClient::json(FakeClient::openAiReply('from A'))))->sendRequest($request());
        $this->wrapHttpClient(new FakeClient(FakeClient::json(FakeClient::openAiReply('from B'))))->sendRequest($request());

        $this->resetAiEvalState();
        putenv('AI_EVAL_MODE=replay');
        $this->wrapHttpClient(new FakeClient())->sendRequest($request());
        $this->wrapHttpClient(new FakeClient())->sendRequest($request());

        self::assertSame(['from A', 'from B'], array_map(static fn(AiInteraction $i): string => $i->assistantContent, $this->getAllAiInteractions()));
    }

    public function testJudgeAssertionRequiresWrappedClientUnlessInjected(): void
    {
        try {
            $this->assertMatchesRubric('r', 'out');
            self::fail('expected LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('wrapHttpClient()', $e->getMessage());
        }
    }

    public function testLastInteractionWithoutTrafficThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->getLastAiInteraction();
    }

    public function testAssertSampledCountsPassesAndReportsFailures(): void
    {
        $seen = [];
        $this->assertSampled(5, 3, function (int $attempt) use (&$seen): void {
            $seen[] = $attempt;
            self::assertTrue($attempt % 2 === 1, "attempt $attempt is even");
        });
        self::assertSame([1, 2, 3, 4, 5], $seen);

        try {
            $this->assertSampled(4, 3, static fn(int $attempt) => self::assertSame(1, $attempt));
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Only 1 of 4 sampled runs passed (need 3)', $e->getMessage());
            self::assertStringContainsString('#2:', $e->getMessage());
        }
    }

    public function testAssertSampledRejectsImpossibleArguments(): void
    {
        foreach ([[0, 0], [3, 4], [3, -1]] as [$runs, $minPass]) {
            try {
                $this->assertSampled($runs, $minPass, static fn() => null);
                self::fail("accepted runs $runs, minPass $minPass");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('needs runs >= 1', $e->getMessage());
            }
        }
    }

    public function testAssertPassRateRejectsRatesOutsideZeroToOne(): void
    {
        foreach ([80.0, -0.1] as $rate) {
            try {
                $this->assertPassRate([1], $rate, static fn() => null);
                self::fail("accepted minPassRate $rate");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('between 0.0 and 1.0', $e->getMessage());
            }
        }
    }

    public function testAssertSampledLetsNonAssertionExceptionsEscape(): void
    {
        $this->expectException(\DomainException::class);
        $this->assertSampled(2, 1, static fn() => throw new \DomainException('boom'));
    }

    public function testEveryAssertionFailsWhenItShouldAndKeepsTheCustomMessage(): void
    {
        putenv('AI_EVAL_MODE=record');
        $this->runAgent($this->wrapHttpClient($this->liveBackend())); // one issue_refund call, then a final answer
        $this->useJudgeClient(new SpyJudge(0.1, 'off topic'));

        $failing = [
            'assertToolCalled' => static fn() => self::assertToolCalled('delete_account', null, null, 'custom'),
            'assertToolNotCalled' => static fn() => self::assertToolNotCalled('issue_refund', null, 'custom'),
            'assertToolCalledTimes' => static fn() => self::assertToolCalledTimes('issue_refund', 2, null, null, 'custom'),
            'assertToolCallOrder' => static fn() => self::assertToolCallOrder(['issue_refund', 'issue_refund'], null, 'custom'),
            'assertTokenUsageLessThan' => static fn() => self::assertTokenUsageLessThan(100, null, 'custom'),
            'assertLatencyLessThan' => static fn() => self::assertLatencyLessThan(0.0, null, 'custom'),
            'assertMatchesJsonSchema' => static fn() => self::assertMatchesJsonSchema(['type' => 'array'], '{}', 'custom'),
            'assertMatchesRubric' => fn() => $this->assertMatchesRubric('polite', 'answer', 0.5, 'custom'),
            'assertNoHallucination' => fn() => $this->assertNoHallucination('context', 'answer', 0.5, 'custom'),
            // A single interaction narrows the check to that turn: the final answer made no tool call.
            'assertToolCalled on one turn' => fn() => self::assertToolCalled('issue_refund', null, $this->getLastAiInteraction(), 'custom'),
        ];
        foreach ($failing as $name => $assertion) {
            $failed = false;
            try {
                $assertion();
            } catch (AssertionFailedError $e) {
                $failed = true;
                self::assertStringStartsWith('custom', $e->getMessage(), $name);
            }
            self::assertTrue($failed, "$name should have failed");
        }

        self::assertToolNotCalled('issue_refund', $this->getLastAiInteraction());
        self::assertToolCalled('issue_refund', null, $this->getAllAiInteractions()[0], 'the first turn made the call');
    }

    public function testTraitEdgeCases(): void
    {
        $spy = new SpyJudge(0.9);
        $this->useJudgeClient($spy);
        $this->assertNoHallucination('a single context string', 'answer');
        self::assertStringContainsString('a single context string', $spy->user);

        TestContextRegistry::startTest('T::sampled');
        $this->assertSampled(1, 0, static fn() => self::fail('allowed to fail: minPass is 0'));
        self::assertSame(0.0, TestContextRegistry::tests()['T::sampled']['passRates'][0]['minRate']);
        $this->assertSampled(4, 2, static fn(int $attempt) => self::assertLessThan(3, $attempt));
        self::assertSame(['sampled pass@4', 2, 4, 0.5], array_values(array_intersect_key(TestContextRegistry::tests()['T::sampled']['passRates'][1], array_flip(['assertion', 'passed', 'total', 'minRate']))));
        TestContextRegistry::endTest();

        TestContextRegistry::startTest('T::rate');
        $this->assertPassRate(['a' => 1, 'b' => 2], 0.5, static fn(int $n) => self::assertSame(1, $n), 'my eval');
        self::assertSame(
            [['assertion' => 'my eval', 'passed' => 1, 'total' => 2, 'rate' => 0.5, 'minRate' => 0.5]],
            TestContextRegistry::tests()['T::rate']['passRates'],
            'assertPassRate() reports its result',
        );
        TestContextRegistry::endTest();

        try {
            $this->assertPassRate([], 0.5, static fn() => null);
            self::fail('an empty dataset cannot reach a 50% pass rate');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Only 0 of 0 cases passed (0%, need 50%)', $e->getMessage());
        }
        try {
            $this->assertPassRate([1, 2, 3], 0.9, static fn(int $n) => self::assertNotSame(3, $n));
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Only 2 of 3 cases passed (67%, need 90%)', $e->getMessage());
        }
    }

    #[AiVcr(mode: VcrMode::Replay)]
    public function testAttributeModeOverridesTheExtensionMode(): void
    {
        TestContextRegistry::configure(null, VcrMode::Record);
        try {
            $this->expectException(CassetteMissException::class);
            $this->runAgent($this->wrapHttpClient($this->liveBackend()));
        } finally {
            TestContextRegistry::configure(null, null);
        }
    }

}
