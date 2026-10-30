<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions;

use Axitdev\PhpUnitAiEvals\Assertions\Constraints\DecisionConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\MatchesJsonSchemaConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\MatchesRubricConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\NoHallucinationConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\ToolCalledConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\ToolCallOrderConstraint;
use Axitdev\PhpUnitAiEvals\Assertions\Constraints\TraceBudgetConstraint;
use Axitdev\PhpUnitAiEvals\Attributes\AiJudgeConfig;
use Axitdev\PhpUnitAiEvals\Attributes\AiVcr;
use Axitdev\PhpUnitAiEvals\Judge\CachedJudgeClient;
use Axitdev\PhpUnitAiEvals\Judge\JudgeClientInterface;
use Axitdev\PhpUnitAiEvals\Judge\JudgeProvider;
use Axitdev\PhpUnitAiEvals\Judge\JudgeSettings;
use Axitdev\PhpUnitAiEvals\Judge\Psr18JudgeClient;
use Axitdev\PhpUnitAiEvals\Judge\SystemOneJudgeClient;
use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use Axitdev\PhpUnitAiEvals\Trace\Decision;
use Axitdev\PhpUnitAiEvals\Trace\Parser\ProviderPayloadParserInterface;
use Axitdev\PhpUnitAiEvals\Vcr\AiVcrClient;
use Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage;
use Axitdev\PhpUnitAiEvals\Vcr\OccurrenceCounter;
use Axitdev\PhpUnitAiEvals\Vcr\PromptNormalizer;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
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

    private bool $aiJudgeCached = true;

    /** @var array<string, OccurrenceCounter> per cassette path, shared by every client wrapped in this test */
    private array $aiOccurrenceCounters = [];

    #[Before]
    protected function resetAiEvalState(): void
    {
        AiTraceCollector::current()->clear();
        $this->aiVcrClient = null;
        $this->aiJudgeClient = null;
        $this->aiOccurrenceCounters = [];
    }

    protected function wrapHttpClient(ClientInterface $client): AiVcrClient
    {
        $vcr = $this->aiVcrConfig();
        $testName = static::class . '::' . $this->name();
        $storage = new CassetteStorage($this->aiCassettesPath());
        $path = $storage->pathFor($vcr->cassette ?? $testName);

        return $this->aiVcrClient = new AiVcrClient(
            $client,
            $storage,
            $path,
            $testName,
            VcrMode::resolve($vcr->mode ?? TestContextRegistry::configuredMode() ?? VcrMode::Auto),
            AiTraceCollector::current(),
            new PromptNormalizer($vcr->ignorePatterns, $vcr->ignoreDatesAndUuids ?? true),
            $this->aiPayloadParsers(),
            TestContextRegistry::scrubber(),
            occurrences: $this->aiOccurrenceCounters[$path] ??= new OccurrenceCounter(),
        );
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

    /**
     * Replaces the configured judge for this test. Its verdicts are recorded in and replayed from the cassette like
     * the built-in judges' (keyed on its cachePayload()) once wrapHttpClient() has been called; pass $cache: false to
     * call it every time.
     */
    protected function useJudgeClient(JudgeClientInterface $judge, bool $cache = true): void
    {
        $this->aiJudgeClient = $judge;
        $this->aiJudgeCached = $cache;
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
     */
    public static function assertMatchesJsonSchema(array|string $schema, string $json, string $message = ''): void
    {
        Assert::assertThat($json, new MatchesJsonSchemaConstraint($schema), $message);
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
     * @param string|list<string> $rubric One criterion, or several that must all hold. Prefer one per thing checked:
     *                                    the System One judge grades each separately and fails on the weakest.
     */
    public function assertMatchesRubric(
        string|array $rubric,
        string $actualOutput,
        float $threshold = 0.80,
        string $message = '',
    ): void {
        Assert::assertThat($actualOutput, new MatchesRubricConstraint($this->aiJudge(), $rubric, $threshold), $message);
    }

    /**
     * The latest System One answer to $questionId chose $option (and gave it at least $minProbability).
     *
     * @param AiInteraction|list<AiInteraction>|null $interactions Defaults to getAllAiInteractions()
     */
    public static function assertDecision(
        string $questionId,
        string $option,
        float $minProbability = 0.0,
        AiInteraction|array|null $interactions = null,
        string $message = '',
    ): void {
        Assert::assertThat(self::aiInteractionList($interactions), DecisionConstraint::choice($questionId, $option, $minProbability), $message);
    }

    /**
     * The latest System One yes/no answer to $questionId is yes with probability at least $min.
     *
     * @param AiInteraction|list<AiInteraction>|null $interactions Defaults to getAllAiInteractions()
     */
    public static function assertNoulAtLeast(
        string $questionId,
        float $min,
        AiInteraction|array|null $interactions = null,
        string $message = '',
    ): void {
        Assert::assertThat(self::aiInteractionList($interactions), DecisionConstraint::noulAtLeast($questionId, $min), $message);
    }

    /**
     * The latest System One yes/no answer to $questionId is yes with probability at most $max.
     *
     * @param AiInteraction|list<AiInteraction>|null $interactions Defaults to getAllAiInteractions()
     */
    public static function assertNoulAtMost(
        string $questionId,
        float $max,
        AiInteraction|array|null $interactions = null,
        string $message = '',
    ): void {
        Assert::assertThat(self::aiInteractionList($interactions), DecisionConstraint::noulAtMost($questionId, $max), $message);
    }

    /**
     * The latest System One Score for $questionId lies within [$min, $max], on the 0..top-level scale it was asked on.
     *
     * @param AiInteraction|list<AiInteraction>|null $interactions Defaults to getAllAiInteractions()
     */
    public static function assertScoreBetween(
        string $questionId,
        float $min,
        float $max,
        AiInteraction|array|null $interactions = null,
        string $message = '',
    ): void {
        Assert::assertThat(self::aiInteractionList($interactions), DecisionConstraint::scoreBetween($questionId, $min, $max), $message);
    }

    /**
     * The latest System One Choice or Score answer to $questionId has at least this confidence. Noul answers have none.
     *
     * @param AiInteraction|list<AiInteraction>|null $interactions Defaults to getAllAiInteractions()
     */
    public static function assertConfidenceAtLeast(
        string $questionId,
        float $min,
        AiInteraction|array|null $interactions = null,
        string $message = '',
    ): void {
        Assert::assertThat(self::aiInteractionList($interactions), DecisionConstraint::confidenceAtLeast($questionId, $min), $message);
    }

    /**
     * The latest System One answer to $questionId, for checks the assertions don't cover.
     */
    protected function getDecision(string $questionId): Decision
    {
        return DecisionConstraint::latest(AiTraceCollector::current()->all(), $questionId)
            ?? throw new LogicException(sprintf('No System One answer to "%s" was recorded in this test.', $questionId));
    }

    /**
     * Calibration over a dataset: the mean squared gap between each predicted probability and what happened
     * (Brier score; 0 is perfect, 0.25 is what always answering 0.5 gets). $testLogic returns the probability the
     * model gave the outcome and whether the outcome is the true one, e.g. `[$this->getDecision('is_urgent')->value,
     * $case['urgent']]`.
     *
     * @template TCase
     * @param iterable<array-key, TCase> $cases
     * @param callable(TCase, array-key): array{float, bool} $testLogic
     */
    protected function assertBrierScoreAtMost(iterable $cases, float $maxBrier, callable $testLogic, string $name = 'brier score'): void
    {
        if ($maxBrier < 0.0 || $maxBrier > 1.0) {
            throw new InvalidArgumentException(sprintf('assertBrierScoreAtMost() needs a maxBrier between 0.0 and 1.0, got %s.', $maxBrier));
        }
        $errors = [];
        foreach ($cases as $key => $case) {
            AiTraceCollector::current()->clear();
            [$probability, $outcome] = $testLogic($case, $key);
            if ($probability < 0.0 || $probability > 1.0) {
                throw new InvalidArgumentException(sprintf('Case #%s returned the probability %s; expected 0.0 to 1.0.', $key, $probability));
            }
            // A list, not keyed by case: a generator may repeat keys, and every case must count.
            $errors[] = [$key, ($probability - ($outcome ? 1.0 : 0.0)) ** 2];
        }
        if ($errors === []) {
            Assert::fail(sprintf('%s: the dataset has no cases.', $name));
        }
        $brier = array_sum(array_column($errors, 1)) / count($errors);
        usort($errors, static fn(array $a, array $b): int => $b[1] <=> $a[1]);
        $worst = array_map(static fn(array $e): string => sprintf('#%s: %.3f', $e[0], $e[1]), array_slice($errors, 0, 5));

        Assert::assertLessThanOrEqual(
            $maxBrier,
            $brier,
            sprintf("%s is %.3f over %d cases (need <= %.3f). Worst cases (squared error):\n%s", $name, $brier, count($errors), $maxBrier, implode("\n", $worst)),
        );
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
        $vcr = $this->aiVcrClient;
        if ($this->aiJudgeClient !== null) {
            return $vcr !== null && $this->aiJudgeCached ? new CachedJudgeClient($vcr, $this->aiJudgeClient) : $this->aiJudgeClient;
        }
        if ($vcr === null) {
            throw new LogicException('Call wrapHttpClient() before using Judge assertions, or inject one with useJudgeClient().');
        }
        [$method, $class] = $this->attributes(AiJudgeConfig::class);
        $settings = JudgeSettings::resolve(new AiJudgeConfig(
            $method->model ?? $class?->model,
            $method->baseUri ?? $class?->baseUri,
            $method->temperature ?? $class?->temperature,
            $method->jsonMode ?? $class?->jsonMode,
            $method->provider ?? $class?->provider,
        ));
        // The judge talks to the wrapped client directly: its traffic is cached as verdicts, not as agent calls.
        $live = match ($settings->provider) {
            JudgeProvider::OpenAiCompatible => new Psr18JudgeClient($vcr->inner(), $settings),
            JudgeProvider::SystemOne => new SystemOneJudgeClient($vcr->inner(), $settings),
        };

        return new CachedJudgeClient($vcr, $live);
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
