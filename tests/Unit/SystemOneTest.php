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

        self::assertSame(['system-one', 'jev-1.13.0', 428, 73, ''], [$i->provider, $i->model, $i->promptTokens, $i->completionTokens, $i->assistantContent]);
        self::assertSame([], $i->toolCalls);
        self::assertEquals(new Decision('department', 'choice', 'technical', ['technical' => 0.95, 'billing' => 0.05], 0.9), $i->decisions['department']);
        self::assertSame([0.67, 0.5, 2], [$i->decisions['frustration']->value, $i->decisions['frustration']->confidence, $i->decisions['frustration']->topLevel()]);
        self::assertEquals(new Decision('is_urgent', 'noul', 0.99), $i->decisions['is_urgent']);
        self::assertSame(0.0, $i->decisions['department']->probabilityOf('sales'));
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

        self::assertThat($trace, DecisionConstraint::choice('department', 'technical', 0.9));
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
                self::assertStringContainsString('the System One question', $e->getMessage());
            }
        }
    }

    public function testTraitDecisionHelpersReadTheCollectedTrace(): void
    {
        AiTraceCollector::current()->record((new SystemOnePayloadParser())->parse(self::ticketRequest(), self::ticketResponse(), 1.0), false);

        self::assertDecision('department', 'technical', 0.9);
        self::assertNoulAtLeast('is_urgent', 0.9);
        self::assertNoulAtMost('is_urgent', 1.0);
        self::assertScoreBetween('frustration', 0.0, 1.0);
        self::assertConfidenceAtLeast('department', 0.9);
        self::assertSame(0.67, $this->getDecision('frustration')->value);

        $this->expectException(LogicException::class);
        $this->getDecision('nope');
    }

    public function testBrierScoreMeasuresCalibrationAcrossCases(): void
    {
        $cases = ['sure-yes' => [0.9, true], 'sure-no' => [0.2, false], 'wrong' => [0.7, false]];
        $this->assertBrierScoreAtMost($cases, 0.19, static fn(array $c): array => $c); // (0.01 + 0.04 + 0.49) / 3 = 0.18

        try {
            $this->assertBrierScoreAtMost($cases, 0.17, static fn(array $c): array => $c, 'urgency calibration');
            self::fail('expected failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('urgency calibration is 0.180 over 3 cases (need <= 0.170)', $e->getMessage());
            self::assertStringContainsString("Worst cases (squared error):\n#wrong: 0.490\n#sure-no: 0.040", $e->getMessage());
        }

        foreach ([[[1 => [1.5, true]], 0.1], [[], 0.1]] as [$bad, $max]) {
            try {
                $this->assertBrierScoreAtMost($bad, $max, static fn(array $c): array => $c);
                self::fail('expected rejection');
            } catch (InvalidArgumentException|AssertionFailedError) {
                $this->addToAssertionCount(1);
            }
        }
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

        putenv('AI_EVAL_JUDGE_PROVIDER=System-One');
        self::assertSame(JudgeProvider::SystemOne, JudgeSettings::resolve()->provider, 'case-insensitive');
        putenv('AI_EVAL_JUDGE_BASE_URI=http://proxy.test/v1');
        self::assertSame('', JudgeSettings::resolve()->apiKey, 'TYPESAFE_API_KEY must not leak to another host');
        self::assertSame(JudgeProvider::OpenAiCompatible, JudgeSettings::resolve(new AiJudgeConfig(provider: 'openai'))->provider, 'the attribute wins');

        putenv('AI_EVAL_JUDGE_PROVIDER=jev');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown judge provider "jev"; expected one of: openai, system-one.');
        JudgeSettings::resolve();
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

    public function testInteractionsWithoutDecisionsKeepWorking(): void
    {
        $plain = new AiInteraction('openai', 'm', 1, 1, 0, 1.0, 'hi');
        self::assertSame([], $plain->decisions);
        self::assertThat([$plain], self::logicalNot(DecisionConstraint::noulAtLeast('x', 0.0)));
    }
}
