<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Assertions\AiAssertionsTrait;
use Axitdev\PhpUnitAiEvals\Judge\JudgeClientInterface;
use Axitdev\PhpUnitAiEvals\Judge\JudgePrompts;
use Axitdev\PhpUnitAiEvals\Judge\JudgeVerdict;
use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use Axitdev\PhpUnitAiEvals\Tests\Support\SpyJudge;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class MultilingualJudgeTest extends TestCase
{
    use AiAssertionsTrait;

    #[After]
    protected function resetRegistry(): void
    {
        TestContextRegistry::reset();
    }

    public function testJudgePromptsGradeMeaningInAnyLanguage(): void
    {
        foreach ([JudgePrompts::RUBRIC_SYSTEM, JudgePrompts::HALLUCINATION_SYSTEM, JudgePrompts::SIMILARITY_SYSTEM] as $prompt) {
            self::assertStringContainsString('may be in any language', $prompt);
        }
    }

    public function testNonEnglishTextReachesTheJudgeUnchanged(): void
    {
        $judge = new SpyJudge(0.9);
        $this->useJudgeClient($judge);

        $this->assertMatchesRubric('Відповідь ввічлива', 'Дякуємо! Ваше замовлення №404 повернуто.');
        self::assertStringContainsString("RUBRIC:\nВідповідь ввічлива", $judge->user);
        self::assertStringContainsString('Дякуємо! Ваше замовлення №404 повернуто.', $judge->user);

        $this->assertSemanticallySimilar('Your refund was issued.', 'Zwrot został zlecony.');
        self::assertSame(JudgePrompts::SIMILARITY_SYSTEM, $judge->system);
        self::assertStringContainsString("REFERENCE:\nYour refund was issued.", $judge->user);
        self::assertStringContainsString('Zwrot został zlecony.', $judge->user);
    }

    public function testSimilarityFailureShowsScoreAndReason(): void
    {
        $this->useJudgeClient(new SpyJudge(0.3, 'Mówi o wymianie, nie o zwrocie.'));

        try {
            $this->assertSemanticallySimilar('Your refund was issued.', 'Wymiana została zlecona.');
            self::fail('expected a failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('means the same as "Your refund was issued." (similarity >= 0.80)', $e->getMessage());
            self::assertStringContainsString('Judge score 0.30 (threshold 0.80): Mówi o wymianie, nie o zwrocie.', $e->getMessage());
        }
    }

    public function testRubricVariantsReportEachFailingVariant(): void
    {
        $this->useJudgeClient(self::scoring(['Refund issued' => 0.95, 'Zwrot' => 0.4, 'Повернення' => 0.2]));

        try {
            $this->assertVariantsMatchRubric('Confirms the refund politely', [
                'en' => 'Refund issued. Thank you!',
                'pl' => 'Zwrot... może.',
                'uk' => 'Повернення неможливе.',
            ]);
            self::fail('expected a failure');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('2 of 3 variants do not satisfy the rubric "Confirms the refund politely":', $e->getMessage());
            self::assertStringContainsString('  [pl] score 0.40 (threshold 0.80): graded', $e->getMessage());
            self::assertStringContainsString('  [uk] score 0.20 (threshold 0.80): graded', $e->getMessage());
            self::assertStringContainsString('Passed: en', $e->getMessage());
        }

        $tracked = array_column(TestContextRegistry::tests()[TestContextRegistry::UNKNOWN_TEST]['judge'] ?? [], 'assertion');
        self::assertSame([
            'satisfies the rubric "Confirms the refund politely" [en]',
            'satisfies the rubric "Confirms the refund politely" [pl]',
            'satisfies the rubric "Confirms the refund politely" [uk]',
        ], $tracked, 'each variant is its own baseline entry');
    }

    public function testAllVariantsPassing(): void
    {
        $this->useJudgeClient(new SpyJudge(0.9));

        $this->assertVariantsMatchRubric('Polite', ['en' => 'Thanks', 'pl' => 'Dzięki']);
        $this->assertVariantsSemanticallySimilar('Thank you', ['pl' => 'Dziękuję', 'uk' => 'Дякую']);
    }

    /**
     * A judge that scores by which key appears in the graded output.
     *
     * @param array<string, float> $scores
     */
    private static function scoring(array $scores): JudgeClientInterface
    {
        return new class ($scores) implements JudgeClientInterface {
            /** @param array<string, float> $scores */
            public function __construct(private readonly array $scores) {}

            public function judge(string $systemPrompt, string $userPrompt): JudgeVerdict
            {
                foreach ($this->scores as $needle => $score) {
                    if (str_contains($userPrompt, $needle)) {
                        return new JudgeVerdict($score, 'graded');
                    }
                }

                return new JudgeVerdict(0.0, 'unknown');
            }
        };
    }
}
