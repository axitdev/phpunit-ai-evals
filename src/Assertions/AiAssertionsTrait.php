<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions;

use Axitdev\PhpUnitAiEvals\Assertions\Constraints\MatchesJsonSchemaConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\MatchesRubricConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\NoHallucinationConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\SemanticSimilarityConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\ToolCalledConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\ToolCallOrderConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\TraceBudgetConstraint;
use Axitdev\PhpUnitAiEvals\Attributes\AiJudgeConfig;
use Axitdev\PhpUnitAiEvals\Attributes\AiVcr;
use Axitdev\PhpUnitAiEvals\Conversation\Conversation;
use Axitdev\PhpUnitAiEvals\Conversation\Turn;
use Axitdev\PhpUnitAiEvals\Judge\CachedJudgeClient;
use Axitdev\PhpUnitAiEvals\Judge\JudgeClientInterface;
use Axitdev\PhpUnitAiEvals\Judge\JudgeSettings;
use Axitdev\PhpUnitAiEvals\Judge\LazyJudgeClient;
use Axitdev\PhpUnitAiEvals\Judge\Psr18JudgeClient;
use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use Axitdev\PhpUnitAiEvals\Trace\Parser\ProviderPayloadParserInterface;
use Axitdev\PhpUnitAiEvals\Vcr\AiVcrClient;
use Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage;
use Axitdev\PhpUnitAiEvals\Vcr\Exception\CassetteMissException;
use Axitdev\PhpUnitAiEvals\Vcr\MatchingRules;
use Axitdev\PhpUnitAiEvals\Vcr\OccurrenceCounter;
use Axitdev\PhpUnitAiEvals\Vcr\PromptNormalizer;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\IncompleteTest;
use PHPUnit\Framework\SkippedTest;
use Psr\Http\Client\ClientInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait AiAssertionsTrait
{
    private ?AiVcrClient $aiVcrClient = null;

    private ?JudgeClientInterface $aiJudgeClient = null;

    /** @var array<string, OccurrenceCounter> per cassette path, shared by every client wrapped in this test */
    private array $aiOccurrenceCounters = [];

    /** @var list<AiVcrClient> every client wrapped in this test */
    private array $aiVcrClients = [];

    /** @var array{string, int}|null conversation name and turn being answered */
    private ?array $aiActiveTurn = null;

    #[Before]
    protected function resetAiEvalState(): void
    {
        AiTraceCollector::current()->clear();
        $this->aiVcrClient = null;
        $this->aiJudgeClient = null;
        $this->aiOccurrenceCounters = [];
        $this->aiVcrClients = [];
        $this->aiActiveTurn = null;
    }

    /**
     * Replay mode must never fall through to the network. A miss throws, but code under test can catch and swallow it
     * (a retry wrapper, a fallback answer), and the test could then pass on behaviour that was never recorded. This
     * fails such a test anyway.
     */
    #[After]
    protected function failOnSwallowedCassetteMisses(): void
    {
        $clients = $this->aiVcrClients;
        $this->aiVcrClients = [];
        $status = $this->status();
        if ((!$status->isSuccess() && !$status->isUnknown()) || $this->aiExpectsCassetteMiss()) {
            return; // the miss, or something else, already failed the test; or the test is about the miss
        }
        $misses = array_merge([], ...array_map(static fn(AiVcrClient $c): array => $c->misses(), $clients));
        if ($misses !== []) {
            throw new AssertionFailedError(sprintf(
                "%d request(s) missed the cassette in replay mode, and the code under test caught the error instead of failing.\n\n%s",
                count($misses),
                $misses[0]->getMessage(),
            ));
        }
    }

    protected function wrapHttpClient(ClientInterface $client): AiVcrClient
    {
        $vcr = $this->aiVcrConfig();
        $testName = static::class . '::' . $this->name();
        $storage = new CassetteStorage($this->aiCassettesPath());
        $path = $storage->pathFor($vcr->cassette ?? $testName);

        $vcrClient = new AiVcrClient(
            $client,
            $storage,
            $path,
            $testName,
            VcrMode::resolve($vcr->mode ?? TestContextRegistry::configuredMode() ?? VcrMode::Auto),
            AiTraceCollector::current(),
            new PromptNormalizer($vcr->ignorePatterns, $vcr->ignoreDatesAndUuids ?? true, $this->aiMatchingRules()),
            $this->aiPayloadParsers(),
            TestContextRegistry::scrubber(),
            occurrences: $this->aiOccurrenceCounters[$path] ??= new OccurrenceCounter(),
        );
        if ($this->aiActiveTurn !== null) {
            $vcrClient->enterTurn(...$this->aiActiveTurn);
        }
        $this->aiVcrClients[] = $vcrClient;

        return $this->aiVcrClient = $vcrClient;
    }

    /**
     * Override to recognise more providers, e.g. `[new MyParser(), ...AiVcrClient::defaultParsers()]`.
     *
     * @return list<ProviderPayloadParserInterface>
     */
    protected function aiPayloadParsers(): array
    {
        return AiVcrClient::defaultParsers();
    }

    protected function aiCassettesPath(): string
    {
        $env = getenv('AI_EVAL_CASSETTES_PATH');

        return is_string($env) && $env !== ''
            ? $env
            : TestContextRegistry::cassettesPath() ?? (string) getcwd() . '/tests/fixtures/ai-cassettes';
    }

    protected function useJudgeClient(JudgeClientInterface $judge): void
    {
        $this->aiJudgeClient = $judge;
    }

    protected function getLastAiInteraction(): AiInteraction
    {
        return AiTraceCollector::current()->last()
            ?? throw new LogicException('No AI interaction was recorded in this test. Did requests go through wrapHttpClient()?');
    }

    /**
     * @return list<AiInteraction>
     */
    protected function getAllAiInteractions(): array
    {
        return AiTraceCollector::current()->all();
    }

    /**
     * @param array<string, mixed>|null $expectedArgumentsSubset
     * @param AiInteraction|list<AiInteraction>|null $interactions Defaults to getAllAiInteractions()
     */
    public static function assertToolCalled(
        string $toolName,
        ?array $expectedArgumentsSubset = null,
        AiInteraction|array|null $interactions = null,
        string $message = '',
    ): void {
        Assert::assertThat(self::aiInteractionList($interactions), new ToolCalledConstraint($toolName, $expectedArgumentsSubset), $message);
    }

    /**
     * @param AiInteraction|list<AiInteraction>|null $interactions Defaults to getAllAiInteractions()
     */
    public static function assertToolNotCalled(
        string $toolName,
        AiInteraction|array|null $interactions = null,
        string $message = '',
    ): void {
        Assert::assertThat(
            self::aiInteractionList($interactions),
            Assert::logicalNot(new ToolCalledConstraint($toolName)),
            $message,
        );
    }

    /**
     * @param AiInteraction|list<AiInteraction>|null $interactions Defaults to sum across getAllAiInteractions()
     */
    public static function assertTokenUsageLessThan(
        int $maxTotalTokens,
        AiInteraction|array|null $interactions = null,
        string $message = '',
    ): void {
        Assert::assertThat(self::aiInteractionList($interactions), TraceBudgetConstraint::tokens($maxTotalTokens), $message);
    }

    /**
     * @param array<string, mixed>|null $expectedArgumentsSubset
     * @param AiInteraction|list<AiInteraction>|null $interactions Defaults to getAllAiInteractions()
     */
    public static function assertToolCalledTimes(
        string $toolName,
        int $times,
        ?array $expectedArgumentsSubset = null,
        AiInteraction|array|null $interactions = null,
        string $message = '',
    ): void {
        Assert::assertThat(self::aiInteractionList($interactions), new ToolCalledConstraint($toolName, $expectedArgumentsSubset, $times), $message);
    }

    /**
     * Passes when the tools were called in this relative order; other calls may come before, between or after.
     *
     * @param list<string> $toolNames
     * @param AiInteraction|list<AiInteraction>|null $interactions Defaults to getAllAiInteractions()
     */
    public static function assertToolCallOrder(
        array $toolNames,
        AiInteraction|array|null $interactions = null,
        string $message = '',
    ): void {
        Assert::assertThat(self::aiInteractionList($interactions), new ToolCallOrderConstraint($toolNames), $message);
    }

    /**
     * Sums model latency over the interactions. Replayed interactions report the latency measured when recorded.
     *
     * @param AiInteraction|list<AiInteraction>|null $interactions Defaults to sum across getAllAiInteractions()
     */
    public static function assertLatencyLessThan(
        float $maxMs,
        AiInteraction|array|null $interactions = null,
        string $message = '',
    ): void {
        Assert::assertThat(self::aiInteractionList($interactions), TraceBudgetConstraint::latency($maxMs), $message);
    }

    /**
     * @param array<string, mixed>|string $schema JSON Schema as an array or a JSON string
     * @param string|AiInteraction|null $output The JSON text, an interaction, or null for the last interaction's answer
     */
    public static function assertMatchesJsonSchema(array|string $schema, string|AiInteraction|null $output = null, string $message = ''): void
    {
        Assert::assertThat(StructuredOutput::text($output), new MatchesJsonSchemaConstraint($schema), $message);
    }

    /**
     * A numeric field of a JSON answer is at least $min, e.g. `assertScoreAtLeast('scores.relevance', 0.7)`.
     *
     * @param string $field Dot path (`scores.relevance`, `items.0.score`) or JSON Pointer (`/scores/relevance`)
     * @param string|AiInteraction|null $output The JSON text, an interaction, or null for the last interaction's answer
     */
    public static function assertScoreAtLeast(string $field, float $min, string|AiInteraction|null $output = null, string $message = ''): void
    {
        $value = StructuredOutput::number(StructuredOutput::decode($output), $field);
        Assert::assertGreaterThanOrEqual($min, $value, self::aiMessage($message, sprintf('Field `%s` of the model output is %s, below the minimum of %s.', $field, $value, $min)));
    }

    /**
     * A numeric field of a JSON answer lies within [$min, $max], bounds included.
     *
     * @param string|AiInteraction|null $output The JSON text, an interaction, or null for the last interaction's answer
     */
    public static function assertScoreBetween(string $field, float $min, float $max, string|AiInteraction|null $output = null, string $message = ''): void
    {
        if ($min > $max) {
            throw new InvalidArgumentException(sprintf('assertScoreBetween() needs min <= max, got %s and %s.', $min, $max));
        }
        $value = StructuredOutput::number(StructuredOutput::decode($output), $field);
        $why = sprintf('Field `%s` of the model output is %s, outside [%s, %s].', $field, $value, $min, $max);
        Assert::assertGreaterThanOrEqual($min, $value, self::aiMessage($message, $why));
        Assert::assertLessThanOrEqual($max, $value, self::aiMessage($message, $why));
    }

    /**
     * A field of a JSON answer is one of the allowed values (enum membership), compared strictly: `"1"` is not `1`.
     *
     * @param list<string|int|float|bool|null> $allowed
     * @param string|AiInteraction|null $output The JSON text, an interaction, or null for the last interaction's answer
     */
    public static function assertFieldIn(string $field, array $allowed, string|AiInteraction|null $output = null, string $message = ''): void
    {
        $value = StructuredOutput::field(StructuredOutput::decode($output), $field);
        $render = static fn(mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Assert::assertContains($value, $allowed, self::aiMessage($message, sprintf(
            'Field `%s` of the model output is %s, which is not one of: %s.',
            $field,
            $render($value),
            implode(', ', array_map($render, $allowed)),
        )));
    }

    /**
     * @param list<string>|string $context
     */
    public function assertNoHallucination(
        array|string $context,
        string $actualOutput,
        float $threshold = 0.85,
        string $message = '',
    ): void {
        Assert::assertThat(
            $actualOutput,
            new NoHallucinationConstraint($this->aiJudge(), is_string($context) ? [$context] : $context, $threshold),
            $message,
        );
    }

    /**
     * Rubric, output, or both may be in any language.
     */
    public function assertMatchesRubric(
        string $rubric,
        string $actualOutput,
        float $threshold = 0.80,
        string $message = '',
    ): void {
        Assert::assertThat($actualOutput, new MatchesRubricConstraint($this->aiJudge(), $rubric, $threshold), $message);
    }

    /**
     * Grades every variant of one answer (typically its translations) against the same rubric and fails listing each
     * variant that missed, with its score and the judge's reason.
     *
     * @param array<string, string> $variants Label => text, e.g. `['en' => ..., 'pl' => ..., 'uk' => ...]`
     */
    public function assertVariantsMatchRubric(
        string $rubric,
        array $variants,
        float $threshold = 0.80,
        string $message = '',
    ): void {
        $this->assertVariants($variants, fn(string $label): MatchesRubricConstraint => new MatchesRubricConstraint($this->aiJudge(), $rubric, $threshold, $label), sprintf('the rubric "%s"', $rubric), $message);
    }

    /**
     * The output means the same as the reference, judged by meaning rather than wording; they need not share a
     * language, so one English reference can check every translation.
     */
    public function assertSemanticallySimilar(
        string $reference,
        string $actualOutput,
        float $threshold = 0.80,
        string $message = '',
    ): void {
        Assert::assertThat($actualOutput, new SemanticSimilarityConstraint($this->aiJudge(), $reference, $threshold), $message);
    }

    /**
     * assertSemanticallySimilar() for each variant, failing with the list of variants that missed.
     *
     * @param array<string, string> $variants Label => text
     */
    public function assertVariantsSemanticallySimilar(
        string $reference,
        array $variants,
        float $threshold = 0.80,
        string $message = '',
    ): void {
        $this->assertVariants($variants, fn(string $label): SemanticSimilarityConstraint => new SemanticSimilarityConstraint($this->aiJudge(), $reference, $threshold, $label), 'the reference answer', $message);
    }

    /**
     * Plays a scripted conversation against the agent under test, one user turn at a time, running each turn's
     * assertions on its reply before the next turn is sent.
     *
     * The agent receives the Conversation (its messages() end with the user message to answer) and returns the reply:
     * a string, the AiInteraction that produced it, or null to use the last interaction's text.
     *
     * Each turn is cassetted on its own: its requests match by turn number, the turn's own messages and the fixed
     * parts of the request (system prompt, tools, model), not by earlier replies. Editing one turn re-records just that
     * turn; later turns keep replaying answers recorded against the old history, so re-record the whole test
     * (AI_EVAL_MODE=record) when you want a consistent transcript.
     *
     * @param callable(Conversation): (string|AiInteraction|null) $agent
     * @param list<Turn> $turns
     */
    protected function runConversation(string $name, callable $agent, array $turns): Conversation
    {
        if ($turns === []) {
            throw new InvalidArgumentException('runConversation() needs at least one Turn.');
        }
        $conversation = new Conversation($name);
        foreach (array_values($turns) as $i => $turn) {
            $index = $i + 1;
            $conversation->begin($turn->message);
            $before = count(AiTraceCollector::current()->all());
            $this->aiActiveTurn = [$name, $index];
            foreach ($this->aiVcrClients as $client) {
                $client->enterTurn($name, $index);
            }
            try {
                $reply = $agent($conversation);
            } finally {
                $this->aiActiveTurn = null;
                foreach ($this->aiVcrClients as $client) {
                    $client->leaveTurn();
                }
            }
            $interactions = array_slice(AiTraceCollector::current()->all(), $before);
            $text = match (true) {
                is_string($reply) => $reply,
                $reply instanceof AiInteraction => $reply->assistantContent,
                $interactions !== [] => $interactions[count($interactions) - 1]->assistantContent,
                default => throw new LogicException(sprintf('Turn %d of conversation "%s": the agent returned null and made no AI call through wrapHttpClient(); return the reply text instead.', $index, $name)),
            };
            $conversation->complete($text, $interactions);

            if ($turn->assert === null) {
                continue;
            }
            try {
                ($turn->assert)($text, $conversation);
            } catch (SkippedTest|IncompleteTest $e) {
                throw $e;
            } catch (AssertionFailedError $e) {
                throw new AssertionFailedError(sprintf(
                    "Turn %d of conversation \"%s\" failed.\nUser: %s\nReply: %s\n\n%s",
                    $index,
                    $name,
                    StructuredOutput::preview($turn->message),
                    StructuredOutput::preview($text),
                    $e->getMessage(),
                ), 0, $e);
            }
        }

        return $conversation;
    }

    /**
     * @param callable(int): void $testLogic
     */
    protected function assertSampled(int $runs, int $minPass, callable $testLogic): void
    {
        if ($runs < 1 || $minPass < 0 || $minPass > $runs) {
            throw new InvalidArgumentException(sprintf('assertSampled() needs runs >= 1 and 0 <= minPass <= runs, got runs %d, minPass %d.', $runs, $minPass));
        }
        [$passed, $failures] = self::runEvalCases(range(1, $runs), static fn(int $attempt) => $testLogic($attempt));
        TestContextRegistry::recordPassRate(sprintf('sampled pass@%d', $runs), $passed, $runs, $minPass / $runs);

        Assert::assertGreaterThanOrEqual(
            $minPass,
            $passed,
            sprintf("Only %d of %d sampled runs passed (need %d).\n%s", $passed, $runs, $minPass, implode("\n", $failures)),
        );
    }

    /**
     * @template TCase
     * @param iterable<array-key, TCase> $cases e.g. Dataset::load('tests/evals/refunds.jsonl')
     * @param callable(TCase, array-key): void $testLogic
     */
    protected function assertPassRate(iterable $cases, float $minPassRate, callable $testLogic, string $name = 'pass rate'): void
    {
        if ($minPassRate < 0.0 || $minPassRate > 1.0) {
            throw new InvalidArgumentException(sprintf('assertPassRate() needs a minPassRate between 0.0 and 1.0, got %s.', $minPassRate));
        }
        [$passed, $failures, $total] = self::runEvalCases($cases, $testLogic);
        TestContextRegistry::recordPassRate($name, $passed, $total, $minPassRate);
        $rate = $total === 0 ? 0.0 : $passed / $total;

        Assert::assertGreaterThanOrEqual(
            $minPassRate,
            $rate,
            sprintf("Only %d of %d cases passed (%.0f%%, need %.0f%%).\n%s", $passed, $total, $rate * 100, $minPassRate * 100, implode("\n", $failures)),
        );
    }

    /**
     * @template TCase
     * @param iterable<array-key, TCase> $cases
     * @param callable(TCase, array-key): void $testLogic
     * @return array{int, list<string>, int} passed, failure messages, total
     */
    private static function runEvalCases(iterable $cases, callable $testLogic): array
    {
        $passed = 0;
        $total = 0;
        $failures = [];
        foreach ($cases as $key => $case) {
            $total++;
            AiTraceCollector::current()->clear();
            try {
                $testLogic($case, $key);
                $passed++;
            } catch (SkippedTest|IncompleteTest $e) {
                // These extend AssertionFailedError, but skip or mark the whole test rather than fail one case.
                throw $e;
            } catch (AssertionFailedError $e) {
                $failures[] = sprintf('#%s: %s', $key, $e->getMessage());
            }
        }

        return [$passed, $failures, $total];
    }

    /**
     * @param array<string, string> $variants
     * @param callable(string): (MatchesRubricConstraint|SemanticSimilarityConstraint) $constraintFor
     */
    private function assertVariants(array $variants, callable $constraintFor, string $what, string $message): void
    {
        if ($variants === []) {
            throw new InvalidArgumentException('Pass at least one variant, e.g. [\'en\' => $english, \'pl\' => $polish].');
        }
        $failed = [];
        $passed = [];
        foreach ($variants as $label => $text) {
            $constraint = $constraintFor((string) $label);
            if ($constraint->evaluate($text, '', true) === true) {
                $passed[] = (string) $label;
                continue;
            }
            $verdict = $constraint->verdict();
            $failed[] = sprintf(
                '  [%s] score %.2f (threshold %.2f): %s',
                $label,
                $verdict === null ? 0.0 : $verdict->score,
                $constraint->threshold(),
                $verdict === null ? '' : $verdict->reason,
            );
        }
        if ($failed !== []) {
            Assert::fail(self::aiMessage($message, sprintf(
                "%d of %d variants do not satisfy %s:\n%s\nPassed: %s",
                count($failed),
                count($variants),
                $what,
                implode("\n", $failed),
                $passed === [] ? 'none' : implode(', ', $passed),
            )));
        }
        Assert::assertSame([], $failed); // counts as an assertion
    }

    /**
     * Whether the test called expectException() with CassetteMissException or a parent of it. PHPUnit has no public
     * getter for this, so it is read from TestCase; if that ever moves, the guard stays quiet rather than wrong.
     */
    private function aiExpectsCassetteMiss(): bool
    {
        try {
            $expected = (new \ReflectionProperty(\PHPUnit\Framework\TestCase::class, 'expectedException'))->getValue($this);
        } catch (\ReflectionException) {
            return true;
        }

        return is_string($expected) && is_a(CassetteMissException::class, $expected, true);
    }

    private static function aiMessage(string $custom, string $default): string
    {
        return $custom === '' ? $default : $custom . "\n" . $default;
    }

    /**
     * @param AiInteraction|list<AiInteraction>|null $interactions
     * @return list<AiInteraction>
     */
    private static function aiInteractionList(AiInteraction|array|null $interactions): array
    {
        return match (true) {
            $interactions === null => AiTraceCollector::current()->all(),
            $interactions instanceof AiInteraction => [$interactions],
            default => $interactions,
        };
    }

    private function aiJudge(): JudgeClientInterface
    {
        if ($this->aiJudgeClient !== null) {
            return $this->aiJudgeClient;
        }
        $vcr = $this->aiVcrClient ?? throw new LogicException('Call wrapHttpClient() before using Judge assertions, or inject one with useJudgeClient().');
        [$method, $class] = $this->attributes(AiJudgeConfig::class);
        $settings = JudgeSettings::resolve(new AiJudgeConfig(
            $method->model ?? $class?->model,
            $method->baseUri ?? $class?->baseUri,
            $method->temperature ?? $class?->temperature,
            $method->jsonMode ?? $class?->jsonMode,
        ));
        $http = $vcr->inner();

        $live = new LazyJudgeClient(static fn(): Psr18JudgeClient => new Psr18JudgeClient($http, $settings));

        return $this->aiJudgeClient = new CachedJudgeClient($vcr, $live, $settings->model);
    }

    private function aiVcrConfig(): AiVcr
    {
        [$method, $class] = $this->attributes(AiVcr::class);

        return new AiVcr(
            $method->cassette ?? $class?->cassette,
            $method->mode ?? $class?->mode,
            ($method?->ignorePatterns ?: null) ?? $class->ignorePatterns ?? [],
            $method->ignoreDatesAndUuids ?? $class?->ignoreDatesAndUuids,
        );
    }

    /**
     * The extension's matching rules, plus the class's #[AiVcr], plus the method's.
     */
    private function aiMatchingRules(): MatchingRules
    {
        [$method, $class] = $this->attributes(AiVcr::class);
        $rules = TestContextRegistry::matchingRules();
        foreach ([$class, $method] as $attribute) {
            if ($attribute !== null) {
                $rules = $rules->merge($attribute->matchingRules());
            }
        }

        return $rules;
    }

    /**
     * @template T of object
     * @param class-string<T> $attribute
     * @return array{T|null, T|null} the method's attribute, then the class's
     */
    private function attributes(string $attribute): array
    {
        return [
            ((new ReflectionMethod($this, $this->name()))->getAttributes($attribute)[0] ?? null)?->newInstance(),
            ((new ReflectionClass($this))->getAttributes($attribute)[0] ?? null)?->newInstance(),
        ];
    }
}
