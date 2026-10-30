<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\DecisionConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\MatchesRubricConstraint;
use Axitdev\PhpUnitAiEvals\Attributes\AiJudgeConfig;
use Axitdev\PhpUnitAiEvals\Judge\CachedJudgeClient;
use Axitdev\PhpUnitAiEvals\Judge\JudgeProvider;
use Axitdev\PhpUnitAiEvals\Judge\JudgeRequest;
use Axitdev\PhpUnitAiEvals\Judge\JudgeSettings;
use Axitdev\PhpUnitAiEvals\Judge\SystemOneJudgeClient;
use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\SpyJudge;
use Axitdev\PhpUnitAiEvals\Tests\Support\VcrTestTrait;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use Axitdev\PhpUnitAiEvals\Trace\Decision;
use Axitdev\PhpUnitAiEvals\Trace\Parser\SystemOnePayloadParser;
use Axitdev\PhpUnitAiEvals\Vcr\Cassette;
use Axitdev\PhpUnitAiEvals\Vcr\Exception\CassetteMissException;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * System One models (TypeSafe Jev): tracing their typed answers, asserting on them, and judging with them.
 */
final class SystemOneTest extends TestCase
{
    use AiAssertionsTrait;
    use VcrTestTrait;

    private const URI = 'https://api.typesafe.ai/v1/systemone';

    private const VARS = ['AI_EVAL_JUDGE_PROVIDER', 'AI_EVAL_JUDGE_BASE_URI', 'AI_EVAL_JUDGE_MODEL', 'AI_EVAL_JUDGE_API_KEY', 'TYPESAFE_API_KEY', 'OPENAI_API_KEY'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    #[Before]
    protected function clearJudgeEnv(): void
    {
        foreach (self::VARS as $var) {
            $this->savedEnv[$var] = getenv($var);
            putenv($var);
        }
    }

    #[After]
    protected function restoreJudgeEnv(): void
    {
        foreach ($this->savedEnv as $var => $value) {
            putenv($value === false ? $var : "$var=$value");
        }
        AiTraceCollector::current()->clear();
    }

    protected function aiCassettesPath(): string
    {
        return $this->tmp;
    }

    /**
     * @return array<string, mixed>
     */
    private static function ticketRequest(string $state = 'Our API returns 500 on every request.'): array
    {
        return [
            'model' => 'jev-latest',
            'state' => $state,
            'questions' => [
                'department' => ['type' => 'choice', 'instructions' => 'Which team', 'criteria' => ['billing' => 'Payments', 'technical' => 'Bugs']],
                'frustration' => ['type' => 'score', 'instructions' => 'How frustrated', 'criteria' => ['Calm', 'Frustrated', 'Angry']],
                'is_urgent' => ['type' => 'noul', 'instructions' => 'The message is urgent'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ticketResponse(): array
    {
        return [
            'model' => 'jev-1.13.0',
            'answers' => [
                'department' => ['type' => 'choice', 'choice' => 'technical', 'confidence' => 0.9, 'probabilities' => ['technical' => 0.95, 'billing' => 0.05]],
                'frustration' => ['type' => 'score', 'score' => 0.67, 'confidence' => 0.5, 'legend' => ['0' => 'Calm', '1' => 'Frustrated', '2' => 'Angry'], 'probabilities' => ['0' => 0.33, '1' => 0.67, '2' => 0.0]],
                'is_urgent' => ['type' => 'noul', 'noul' => 0.99],
            ],
            'usage' => ['input_tokens' => 428, 'output_tokens' => 73],
        ];
    }

    /**
     * @param array<string, float> $nouls question id => probability
     */
    private static function noulReply(array $nouls, string $model = 'jev-1.13.0'): \Nyholm\Psr7\Response
    {
        return FakeClient::json([
            'model' => $model,
            'answers' => array_map(static fn(float $p): array => ['type' => 'noul', 'noul' => $p], $nouls),
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ]);
    }

    private static function judge(FakeClient $http, string $model = 'jev-latest'): SystemOneJudgeClient
    {
        return new SystemOneJudgeClient($http, new JudgeSettings('https://api.typesafe.ai/v1/', $model, 'ts-key', provider: JudgeProvider::SystemOne));
    }

    public function testParserReadsEveryAnswerTypeAndTheReleaseThatAnswered(): void
    {
        $parser = new SystemOnePayloadParser();
        self::assertTrue($parser->supports(self::URI));
        self::assertTrue($parser->supports('http://localhost:8080/v1/systemone/'));
        self::assertFalse($parser->supports('https://api.typesafe.ai/v1/models'));

        $i = $parser->parse(self::ticketRequest(), self::ticketResponse(), 12.5);

        self::assertSame(['system-one', 'jev-1.13.0', 428, 73, 0, ''], [$i->provider, $i->model, $i->promptTokens, $i->completionTokens, $i->reasoningTokens, $i->assistantContent]);
        self::assertSame([], $i->toolCalls);
        self::assertEquals(new Decision('department', 'choice', 'technical', ['technical' => 0.95, 'billing' => 0.05], 0.9), $i->decisions['department']);
        self::assertSame([0.67, 0.5, 2], [$i->decisions['frustration']->value, $i->decisions['frustration']->confidence, $i->decisions['frustration']->topLevel()]);
        self::assertEquals(new Decision('is_urgent', 'noul', 0.99), $i->decisions['is_urgent']);
        self::assertSame(0.0, $i->decisions['department']->probabilityOf('sales'));
        self::assertSame(0, $i->decisions['is_urgent']->topLevel(), 'no levels, no division by a negative top');
        self::assertSame(0.5, Decision::fromAnswer('n', ['type' => 'choice', 'choice' => 'a', 'probabilities' => ['a' => '0.5']])->probabilityOf('a'), 'probabilities are floats');
    }

    public function testSystemOneCallsAreRecordedAsAgentCallsAndHashedOnStateAndQuestions(): void
    {
        $this->client(new FakeClient(FakeClient::json(self::ticketResponse())), VcrMode::Record)
            ->sendRequest(FakeClient::request(self::URI, self::ticketRequest()));

        $entry = $this->cassette()->interactions()[0];
        self::assertSame([Cassette::KIND_AGENT, 'system-one', 'jev-latest'], [$entry['kind'], $entry['provider'], $entry['model']]);
        self::assertSame('Our API returns 500 on every request.', Payload::get($entry, 'request', 'normalized_payload', 'state'));

        $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request(self::URI, self::ticketRequest()));
        self::assertSame('technical', $this->collector->last()?->decisions['department']->value, 'replayed answers are traced');

        $changedQuestion = self::ticketRequest();
        $changedQuestion['questions'] = ['is_urgent' => ['type' => 'noul', 'instructions' => 'The customer threatens to leave']] + Payload::map($changedQuestion['questions']);
        foreach (['another state' => self::ticketRequest('All good, thanks!'), 'another question' => $changedQuestion] as $name => $payload) {
            try {
                $this->client(new FakeClient(), VcrMode::Replay)->sendRequest(FakeClient::request(self::URI, $payload));
                self::fail("$name should not replay the recorded answers");
            } catch (CassetteMissException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDecisionAssertionsCheckTheLatestAnswer(): void
    {
        $parser = new SystemOnePayloadParser();
        $older = $parser->parse([], ['answers' => ['is_urgent' => ['type' => 'noul', 'noul' => 0.1]]], 1.0);
        $latest = $parser->parse(self::ticketRequest(), self::ticketResponse(), 1.0);
        $trace = [$older, $latest];

        self::assertThat($trace, DecisionConstraint::choice('department', 'technical'));
        self::assertThat($trace, DecisionConstraint::choice('department', 'technical', 0.95), 'the minimum is inclusive');
        self::assertThat($trace, DecisionConstraint::scoreBetween('frustration', 0.67, 0.67), 'both bounds are inclusive');
        self::assertSame('chose "technical"', DecisionConstraint::choice('department', 'technical')->toString());
        self::assertSame('chose "technical" with probability >= 0.90', DecisionConstraint::choice('department', 'technical', 0.9)->toString());
        self::assertThat($trace, self::logicalNot(DecisionConstraint::choice('department', 'technical', 0.96)));
        self::assertThat($trace, self::logicalNot(DecisionConstraint::choice('department', 'billing')));
        self::assertThat($trace, DecisionConstraint::noulAtLeast('is_urgent', 0.99), 'the latest answer wins');
        self::assertThat([$older], DecisionConstraint::noulAtMost('is_urgent', 0.1));
        self::assertThat($trace, DecisionConstraint::scoreBetween('frustration', 0.5, 1.0));
        self::assertThat($trace, self::logicalNot(DecisionConstraint::scoreBetween('frustration', 1.0, 2.0)));
        self::assertThat($trace, DecisionConstraint::confidenceAtLeast('frustration', 0.5));
        self::assertThat($trace, self::logicalNot(DecisionConstraint::confidenceAtLeast('is_urgent', 0.0)), 'a Noul has no confidence');

        foreach ([
            'No recorded System One answer has that question id.' => DecisionConstraint::noulAtLeast('missing', 0.5),
            'It is a choice question, not a noul question.' => DecisionConstraint::noulAtLeast('department', 0.5),
            'Answer: score 0.67, confidence 0.50, probabilities {"0":0.33,"1":0.67,"2":0}' => DecisionConstraint::scoreBetween('frustration', 2.0, 2.0),
            'Answer: choice "technical", confidence 0.90' => DecisionConstraint::choice('department', 'billing'),
        ] as $detail => $constraint) {
            try {
                self::assertThat($trace, $constraint);
                self::fail('expected failure: ' . $detail);
            } catch (AssertionFailedError $e) {
                self::assertStringContainsString($detail, $e->getMessage());
                self::assertMatchesRegularExpression('/Failed asserting that the System One question "\w+" \w/', $e->getMessage());
            }
        }
    }

    public function testTraitDecisionHelpersReadTheCollectedTraceAndFailWithTheCustomMessage(): void
    {
        AiTraceCollector::current()->record((new SystemOnePayloadParser())->parse(self::ticketRequest(), self::ticketResponse(), 1.0), false);

        self::assertDecision('department', 'technical');
        self::assertNoulAtLeast('is_urgent', 0.9);
        self::assertNoulAtMost('is_urgent', 1.0);
        self::assertScoreBetween('frustration', 0.0, 1.0);
        self::assertConfidenceAtLeast('department', 0.9);
        self::assertSame(0.67, $this->getDecision('frustration')->value);

        $failing = [
            'assertDecision' => static fn() => self::assertDecision('department', 'billing', 0.0, null, 'custom'),
            'assertDecision probability' => static fn() => self::assertDecision('department', 'technical', 0.99, null, 'custom'),
            'assertNoulAtLeast' => static fn() => self::assertNoulAtLeast('is_urgent', 1.0, null, 'custom'),
            'assertNoulAtMost' => static fn() => self::assertNoulAtMost('is_urgent', 0.5, null, 'custom'),
            'assertScoreBetween' => static fn() => self::assertScoreBetween('frustration', 1.0, 2.0, null, 'custom'),
            'assertConfidenceAtLeast' => static fn() => self::assertConfidenceAtLeast('frustration', 0.6, null, 'custom'),
            'an explicit empty trace' => static fn() => self::assertNoulAtLeast('is_urgent', 0.0, [], 'custom'),
        ];
        foreach ($failing as $name => $assertion) {
            try {
                $assertion();
                self::fail("$name should have failed");
            } catch (AssertionFailedError $e) {
                self::assertStringStartsWith('custom', $e->getMessage(), $name);
            }
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No System One answer to "nope" was recorded in this test.');
        $this->getDecision('nope');
    }

    public function testBrierScoreMeasuresCalibrationAcrossCases(): void
    {
        $cases = ['sure-yes' => [0.9, true], 'sure-no' => [0.2, false], 'wrong' => [0.7, false]];
        $this->assertBrierScoreAtMost($cases, 0.18, static fn(array $c): array => $c); // (0.01 + 0.04 + 0.49) / 3 = 0.18

        try {
            $this->assertBrierScoreAtMost($cases, 0.17, static fn(array $c): array => $c, 'urgency calibration');
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('urgency calibration is 0.180 over 3 cases (need <= 0.170)', $e->getMessage());
            self::assertStringContainsString("Worst cases (squared error):\n#wrong: 0.490\n#sure-no: 0.040\n#sure-yes: 0.010", $e->getMessage());
        }

        // A list dataset has integer keys, and only the five worst cases are listed.
        try {
            $this->assertBrierScoreAtMost(array_fill(0, 7, [1.0, false]), 0.5, static fn(array $c): array => $c);
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString("#0: 1.000\n#1: 1.000\n#2: 1.000\n#3: 1.000\n#4: 1.000\nFailed asserting", $e->getMessage());
        }

        $repeatedKeys = (static function () {
            yield 'same' => [1.0, true];
            yield 'same' => [0.0, true];
        })();
        try {
            $this->assertBrierScoreAtMost($repeatedKeys, 0.4, static fn(array $c): array => $c);
            self::fail('both cases count even though a generator gave them the same key');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('is 0.500 over 2 cases', $e->getMessage());
        }

        AiTraceCollector::current()->record(new AiInteraction('openai', 'm', 1, 1, 0, 1.0, 'before'), false);
        $this->assertBrierScoreAtMost([[0.0, false]], 0.0, function (array $c): array {
            self::assertSame([], $this->getAllAiInteractions(), 'each case starts with an empty trace');

            return $c;
        });
    }

    public function testBrierScoreRejectsBadInput(): void
    {
        foreach ([
            'maxBrier' => fn() => $this->assertBrierScoreAtMost([[0.5, true]], 1.1, static fn(array $c): array => $c),
            'negative maxBrier' => fn() => $this->assertBrierScoreAtMost([[0.5, true]], -0.1, static fn(array $c): array => $c),
            'probability above 1' => fn() => $this->assertBrierScoreAtMost([[1.5, true]], 1.0, static fn(array $c): array => $c),
            'negative probability' => fn() => $this->assertBrierScoreAtMost([[-0.1, false]], 1.0, static fn(array $c): array => $c),
        ] as $name => $call) {
            try {
                $call();
                self::fail("$name should be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertBrierScoreAtMost([[0.0, true], [1.0, true]], 1.0, static fn(array $c): array => $c); // 0, 1 and maxBrier 1 are valid

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('brier score: the dataset has no cases.');
        $this->assertBrierScoreAtMost([], 0.1, static fn(array $c): array => $c);
    }

    public function testRubricJudgeAsksOneNoulPerCriterionAndScoresTheWeakest(): void
    {
        $http = new FakeClient(self::noulReply(['criterion_1' => 0.97, 'criterion_2' => 0.31]));
        $verdict = self::judge($http)->judge(JudgeRequest::rubric(['Gives a number of days', ' Stays polite ', ''], 'Refunds take 5 days.'));

        self::assertSame(0.31, $verdict->score);
        self::assertSame('P(yes) per question, lowest first: "Stays polite" 0.31; "Gives a number of days" 0.97 [jev-1.13.0]', $verdict->reason);

        $request = $http->requests[0];
        self::assertSame(self::URI, (string) $request->getUri());
        self::assertSame('Bearer ts-key', $request->getHeaderLine('Authorization'));
        self::assertSame([
            'model' => 'jev-latest',
            'state' => 'Refunds take 5 days.',
            'questions' => [
                'criterion_1' => ['type' => 'noul', 'instructions' => 'Gives a number of days'],
                'criterion_2' => ['type' => 'noul', 'instructions' => 'Stays polite'],
            ],
        ], json_decode((string) $request->getBody(), true));
    }

    public function testRubricJudgeEdgeCases(): void
    {
        self::assertSame(['a'], JudgeRequest::rubric(['', ' a '], 'x')->criteria, 'a list, without gaps');

        $verdict = self::judge(new FakeClient(self::noulReply(['criterion_1' => 0.0, 'criterion_2' => 1.0])))->judge(JudgeRequest::rubric(['same', 'same'], 'x'));
        self::assertSame(0.0, $verdict->score, '0 and 1 are valid probabilities');
        self::assertStringStartsWith('P(yes) per question, lowest first: "same" 0.00; "same" 1.00', $verdict->reason, 'a repeated criterion is listed for each question');

        $long = str_repeat('x', 200);
        $verdict = self::judge(new FakeClient(self::noulReply(['criterion_1' => 0.5])))->judge(JudgeRequest::rubric($long, 'x'));
        self::assertStringContainsString('"' . str_repeat('x', 119) . '…" 0.50', $verdict->reason);
    }

    public function testGroundingJudgeChecksEverySentenceAgainstTheContext(): void
    {
        $output = "Refunds take 5 days. You also get a coupon!\n- Contact us anytime\n\n---";
        self::assertSame(['Refunds take 5 days.', 'You also get a coupon!', 'Contact us anytime'], SystemOneJudgeClient::claims($output));

        $http = new FakeClient(self::noulReply(['claim_1' => 0.95, 'claim_2' => 0.04, 'claim_3' => 0.6, 'claim_4' => 0.0]));
        $verdict = self::judge($http)->judge(JudgeRequest::grounding(['Refunds take 5 days.'], $output));

        self::assertSame(0.04, $verdict->score);
        self::assertStringStartsWith('P(yes) per question, lowest first: "You also get a coupon!" 0.04; "Contact us anytime" 0.60; "Refunds take 5 days." 0.95 [', $verdict->reason);
        $sent = json_decode((string) $http->requests[0]->getBody(), true);
        self::assertSame(['context' => ['Refunds take 5 days.'], 'answer' => $output], Payload::get($sent, 'state'));
        self::assertSame('You also get a coupon!', Payload::get($sent, 'questions', 'claim_2', 'instructions', 'claim'));
        self::assertSame('noul', Payload::get($sent, 'questions', 'claim_2', 'type'));
        self::assertSame(['Повернення займає 5 днів.', 'Дякуємо!'], SystemOneJudgeClient::claims('Повернення займає 5 днів. Дякуємо!'), 'letters in any script count as words');
        self::assertSame(['Indented line.'], SystemOneJudgeClient::claims("   Indented line.  "), 'surrounding whitespace is not part of a claim');
        self::assertCount(3, Payload::map(Payload::get($sent, 'questions')), 'answers to questions that were not asked are ignored');

        $none = new FakeClient();
        self::assertSame(1.0, self::judge($none)->judge(JudgeRequest::grounding(['ctx'], " \n- \n"))->score);
        self::assertSame([], $none->requests, 'nothing to check means no call');
    }

    public function testReasonListsTheThreeWeakestAndCountsTheRest(): void
    {
        $nouls = ['criterion_1' => 0.9, 'criterion_2' => 0.8, 'criterion_3' => 0.7, 'criterion_4' => 0.6, 'criterion_5' => 0.5];
        $verdict = self::judge(new FakeClient(self::noulReply($nouls, '')))->judge(JudgeRequest::rubric(['a', 'b', 'c', 'd', 'e'], 'x'));

        self::assertSame('P(yes) per question, lowest first: "e" 0.50; "d" 0.60; "c" 0.70; 2 more', $verdict->reason);
    }

    public function testMalformedAnswersAreErrorsNotVerdicts(): void
    {
        foreach ([['criterion_1' => 1.5], ['other' => 0.5], ['criterion_1' => -0.1]] as $nouls) {
            try {
                self::judge(new FakeClient(self::noulReply($nouls)))->judge(JudgeRequest::rubric('a', 'x'));
                self::fail('expected UnexpectedValueException for ' . json_encode($nouls));
            } catch (UnexpectedValueException $e) {
                self::assertStringContainsString('no probability between 0 and 1 for "criterion_1"', $e->getMessage());
            }
        }
    }

    public function testSystemOneVerdictsAreCachedOnTheExactRequest(): void
    {
        $record = new FakeClient(self::noulReply(['criterion_1' => 0.9]));
        $first = (new CachedJudgeClient($this->client(new FakeClient(), VcrMode::Record), self::judge($record)))->judge(JudgeRequest::rubric('polite', 'Thanks!'));

        $entry = $this->cassette()->interactions()[0];
        self::assertSame([Cassette::KIND_JUDGE, 'jev-latest'], [$entry['kind'], $entry['model']]);
        self::assertSame('Thanks!', Payload::get($entry, 'request', 'normalized_payload', 'state'));

        $replayed = (new CachedJudgeClient($this->client(new FakeClient(), VcrMode::Replay), self::judge(new FakeClient())))->judge(JudgeRequest::rubric('polite', 'Thanks!'));
        self::assertEquals($first, $replayed);

        $this->expectException(CassetteMissException::class);
        (new CachedJudgeClient($this->client(new FakeClient(), VcrMode::Replay), self::judge(new FakeClient(), 'jev-1.14.0')))->judge(JudgeRequest::rubric('polite', 'Thanks!'));
    }

    public function testProviderSelectsDefaultsAndOnlySendsTheVendorKeyToTheVendor(): void
    {
        putenv('TYPESAFE_API_KEY=ts');
        putenv('OPENAI_API_KEY=oa');

        $s = JudgeSettings::resolve(new AiJudgeConfig(provider: 'system-one'));
        self::assertSame([JudgeProvider::SystemOne, 'https://api.typesafe.ai/v1', 'jev-latest', 'ts'], [$s->provider, $s->baseUri, $s->model, $s->apiKey]);

        putenv('AI_EVAL_JUDGE_PROVIDER= System-One ');
        self::assertSame(JudgeProvider::SystemOne, JudgeSettings::resolve()->provider, 'case and spaces are ignored');
        putenv('AI_EVAL_JUDGE_BASE_URI=http://proxy.test/v1');
        self::assertSame('', JudgeSettings::resolve()->apiKey, 'TYPESAFE_API_KEY must not leak to another host');
        self::assertSame(JudgeProvider::OpenAiCompatible, JudgeSettings::resolve(new AiJudgeConfig(provider: 'openai'))->provider, 'the attribute wins');

        putenv('AI_EVAL_JUDGE_PROVIDER');
        putenv('AI_EVAL_JUDGE_BASE_URI');
        putenv('AI_EVAL_JUDGE_PROVIDER=jev');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown judge provider "jev"; expected one of: openai, system-one.');
        JudgeSettings::resolve();
    }

    public function testAnAttributeThatSwitchesProviderDoesNotInheritTheEnvironmentJudge(): void
    {
        // The environment configures an Anthropic judge; one test class switches to System One.
        putenv('AI_EVAL_JUDGE_BASE_URI=https://api.anthropic.com/v1');
        putenv('AI_EVAL_JUDGE_MODEL=claude-haiku-5-5');
        putenv('AI_EVAL_JUDGE_API_KEY=sk-ant-secret');
        putenv('TYPESAFE_API_KEY=ts');

        $switched = JudgeSettings::resolve(new AiJudgeConfig(provider: 'system-one'));
        self::assertSame(
            [JudgeProvider::SystemOne, 'https://api.typesafe.ai/v1', 'jev-latest', 'ts'],
            [$switched->provider, $switched->baseUri, $switched->model, $switched->apiKey],
            'the Anthropic key, host and model stay with the Anthropic judge',
        );

        $same = JudgeSettings::resolve(new AiJudgeConfig(provider: 'openai'));
        self::assertSame(['https://api.anthropic.com/v1', 'claude-haiku-5-5', 'sk-ant-secret'], [$same->baseUri, $same->model, $same->apiKey], 'naming the environment\'s own provider keeps its settings');

        putenv('AI_EVAL_JUDGE_PROVIDER=system-one');
        $back = JudgeSettings::resolve(new AiJudgeConfig(provider: 'openai'));
        self::assertSame(['https://api.openai.com/v1', 'gpt-6-luna', ''], [$back->baseUri, $back->model, $back->apiKey], 'and the other way round');
        self::assertSame('sk-ant-secret', JudgeSettings::resolve()->apiKey, 'without an attribute the environment judge is used as configured');
    }

    public function testRubricListsAreDescribedAndValidated(): void
    {
        $spy = new SpyJudge(0.9);
        $constraint = new MatchesRubricConstraint($spy, ['a', 'b']);
        self::assertSame('satisfies the rubric "a; b" (score >= 0.80)', $constraint->toString());
        self::assertThat('out', $constraint);
        self::assertSame(['a', 'b'], $spy->request?->criteria);

        $this->expectException(InvalidArgumentException::class);
        new MatchesRubricConstraint($spy, [' ', '']);
    }

    public function testCustomJudgesAreCachedOnceAVcrClientExistsUnlessOptedOut(): void
    {
        putenv('AI_EVAL_MODE=record');
        try {
            $this->wrapHttpClient(new FakeClient());
            $cached = new SpyJudge(0.9);
            $this->useJudgeClient($cached);
            $this->assertMatchesRubric('polite', 'Thanks!');

            $this->resetAiEvalState(); // what the next run of this test starts with
            putenv('AI_EVAL_MODE=replay');
            $this->wrapHttpClient(new FakeClient());
            $replay = new SpyJudge(0.0);
            $this->useJudgeClient($replay);
            $this->assertMatchesRubric('polite', 'Thanks!');
            self::assertSame(0, $replay->calls, 'served from the cassette');

            $uncached = new SpyJudge(0.9);
            $this->useJudgeClient($uncached, cache: false);
            $this->assertMatchesRubric('polite', 'Thanks!');
            self::assertSame(1, $uncached->calls);
        } finally {
            putenv('AI_EVAL_MODE');
        }
    }

    public function testTraitJudgesWithTheConfiguredProvider(): void
    {
        putenv('AI_EVAL_MODE=record');
        putenv('AI_EVAL_JUDGE_PROVIDER=system-one');
        try {
            $http = new FakeClient(self::noulReply(['criterion_1' => 0.9, 'criterion_2' => 0.8]));
            $this->wrapHttpClient($http);
            $this->assertMatchesRubric(['Concrete', 'Polite'], 'Refunds take 5 days, thank you!', 0.8);

            self::assertSame(self::URI, (string) $http->requests[0]->getUri());
            self::assertSame([], $this->getAllAiInteractions(), 'judge traffic is a verdict, not an agent call');
        } finally {
            putenv('AI_EVAL_MODE');
        }
    }

    public function testInteractionsWithoutDecisionsKeepWorking(): void
    {
        $plain = new AiInteraction('openai', 'm', 1, 1, 0, 1.0, 'hi');
        self::assertSame([], $plain->decisions);
        self::assertThat([$plain], self::logicalNot(DecisionConstraint::noulAtLeast('x', 0.0)));
    }
}
