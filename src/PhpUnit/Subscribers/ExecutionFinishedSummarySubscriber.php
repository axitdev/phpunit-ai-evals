<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\PhpUnit\Subscribers;

use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use PHPUnit\Event\Application\Finished;
use PHPUnit\Event\Application\FinishedSubscriber;

/**
 * Prints the summary on Application\Finished so it lands after PHPUnit's own result output.
 *
 * @phpstan-import-type Totals from TestContextRegistry
 *
 * @internal
 */
final class ExecutionFinishedSummarySubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        $summary = self::render(TestContextRegistry::totals());
        if ($summary !== '') {
            print $summary;
        }
    }

    /**
     * @param Totals $t
     */
    public static function render(array $t): string
    {
        $judged = $t['judgePassed'] + $t['judgeFailed'];
        if ($t['interactions'] === 0 && $judged === 0) {
            return '';
        }

        $lines = ['', 'AI Evals Summary:'];
        $lines[] = sprintf(
            '  Interactions: %d total (%d VCR hits, %d live calls)',
            $t['interactions'],
            $t['vcrHits'],
            $t['liveCalls'],
        );
        $lines[] = sprintf(
            '  Tokens Used:  %s (Prompt: %s / Completion: %s / Reasoning: %s)',
            number_format($t['promptTokens'] + $t['completionTokens']),
            number_format($t['promptTokens']),
            number_format($t['completionTokens']),
            number_format($t['reasoningTokens']),
        );
        if ($judged > 0) {
            $lines[] = sprintf(
                '  Judge Evals:  %d passed%s (Avg Score: %.2f)',
                $t['judgePassed'],
                $t['judgeFailed'] > 0 ? sprintf(', %d failed', $t['judgeFailed']) : '',
                $t['judgeScoreSum'] / $judged,
            );
        }

        return implode("\n", $lines) . "\n\n";
    }
}
