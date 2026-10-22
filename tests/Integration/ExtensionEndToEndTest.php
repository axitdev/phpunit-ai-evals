<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Integration;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\TempDirTrait;
use PHPUnit\Framework\TestCase;

/**
 * Boots the real extension in a child PHPUnit process driven by a phpunit.xml, like a user project would.
 */
final class ExtensionEndToEndTest extends TestCase
{
    use TempDirTrait;

    private const ROOT = __DIR__ . '/../..';

    private function writeConfig(string $extensionParams): void
    {
        file_put_contents($this->tmp . '/phpunit.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit bootstrap="
            XML . realpath(self::ROOT) . <<<XML
            /vendor/autoload.php" colors="false">
                <testsuites><testsuite name="e2e"><file>
            XML . realpath(self::ROOT) . <<<XML
            /tests/Fixtures/AgentFixtureTest.php</file></testsuite></testsuites>
                <extensions>
                    <bootstrap class="Axitdev\\PhpUnitAiEvals\\PhpUnit\\AiEvalsExtension">
                        $extensionParams
                    </bootstrap>
                </extensions>
            </phpunit>
            XML);
    }

    /**
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private function runPhpunit(array $env = []): array
    {
        $base = array_diff_key(getenv(), ['CI' => 1, 'GITHUB_ACTIONS' => 1, 'AI_EVAL_MODE' => 1, 'AI_EVAL_CASSETTES_PATH' => 1, 'AI_EVAL_PRUNE' => 1, 'TEST_TOKEN' => 1, 'AI_EVAL_FIXTURE_FAIL' => 1]);
        $process = proc_open(
            [PHP_BINARY, (string) realpath(self::ROOT . '/vendor/bin/phpunit'), '-c', $this->tmp . '/phpunit.xml', '--no-progress'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmp,
            $env + $base,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $output];
    }

    public function testExtensionConfiguresCassettesPrintsSummaryAndMergesDataProviderRuns(): void
    {
        $this->writeConfig('<parameter name="cassettesPath" value="cassettes"/><parameter name="mode" value="auto"/>'
            . '<parameter name="redactHeaders" value="X-Tenant-Token, X-Other"/>');

        [$code, $first] = $this->runPhpunit();
        self::assertSame(0, $code, $first);
        self::assertStringContainsString('AI Evals Summary:', $first);
        self::assertStringContainsString('Interactions: 2 total (0 VCR hits, 2 live calls)', $first);
        self::assertStringContainsString('Tokens Used:  240 (Prompt: 200 / Completion: 40 / Reasoning: 0)', $first);
        self::assertStringContainsString('Judge Evals:  2 passed (Avg Score: 0.90)', $first);

        // Both DataProvider iterations landed in one cassette, resolved relative to phpunit.xml.
        $files = glob($this->tmp . '/cassettes/*.json') ?: [];
        self::assertCount(1, $files);
        $json = (string) file_get_contents($files[0]);
        self::assertSame(2, substr_count($json, '"kind": "agent_call"'));
        self::assertStringNotContainsString('TENANT-SECRET', $json);
        // Identical judge prompts from both iterations share one (hash, occurrence) entry.
        self::assertSame(1, substr_count($json, '"kind": "judge_eval"'));

        // Second run: the fixtures' fake backend is exhausted, so only the cassette can answer.
        [$code, $second] = $this->runPhpunit();
        self::assertSame(0, $code, $second);
        self::assertStringContainsString('Interactions: 2 total (2 VCR hits, 0 live calls)', $second);
        self::assertStringContainsString('Judge Evals:  2 passed (Avg Score: 0.90)', $second);
    }

    public function testReportIsWrittenAndComparedWithBaseline(): void
    {
        $this->writeConfig('<parameter name="cassettesPath" value="cassettes"/>'
            . '<parameter name="reportPath" value="build/ai-evals.json"/><parameter name="baselinePath" value="baseline.json"/>');

        [$code, $output] = $this->runPhpunit();
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('baseline.json not found; copy a report there to start comparing.', $output);
        self::assertStringNotContainsString('regressions', $output);
        $report = json_decode((string) file_get_contents($this->tmp . '/build/ai-evals.json'), true);
        self::assertIsArray($report);
        self::assertSame(2, Payload::get($report, 'totals', 'interactions'));
        $tests = Payload::map($report['tests'] ?? null);
        self::assertCount(2, $tests);
        $first = (string) array_key_first($tests);
        self::assertStringContainsString('AgentFixtureTest::testAgent', $first);
        self::assertSame(0.9, Payload::get($tests, $first, 'judge', 0, 'score'));

        // A baseline that scored higher makes the replayed run report a regression.
        $baseline = [$first => ['judge' => [['assertion' => Payload::get($tests, $first, 'judge', 0, 'assertion'), 'score' => 1.0]]]];
        file_put_contents($this->tmp . '/baseline.json', json_encode(['tests' => $baseline]));
        [$code, $output] = $this->runPhpunit();
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('AI Evals regressions against the baseline (1):', $output);
        self::assertStringContainsString('1.00 -> 0.90', $output);

        // A drop within baselineTolerance is not a regression.
        $this->writeConfig('<parameter name="cassettesPath" value="cassettes"/><parameter name="baselinePath" value="baseline.json"/>'
            . '<parameter name="baselineTolerance" value="0.15"/>');
        [, $output] = $this->runPhpunit();
        self::assertStringContainsString('no regressions against the baseline', $output);

        // A broken baseline is called out instead of passing for "no regressions".
        file_put_contents($this->tmp . '/baseline.json', '{not json');
        [, $output] = $this->runPhpunit();
        self::assertStringContainsString('baseline.json is not a report (no "tests" object); skipping the comparison.', $output);
        self::assertStringNotContainsString('no regressions', $output);
    }

    public function testUnwritableReportDirectoryIsReported(): void
    {
        file_put_contents($this->tmp . '/build', 'a file where the report directory should be');
        $this->writeConfig('<parameter name="cassettesPath" value="cassettes"/><parameter name="reportPath" value="build/ai-evals.json"/>');

        [, $output] = $this->runPhpunit();

        self::assertStringContainsString('AI Evals: could not write the report: cannot create directory', $output);
    }

    public function testParaTestWorkersWriteTheirOwnReport(): void
    {
        $this->writeConfig('<parameter name="cassettesPath" value="cassettes"/><parameter name="reportPath" value="build/ai-evals.json"/>');

        [$code, $output] = $this->runPhpunit(['TEST_TOKEN' => '3']);

        self::assertSame(0, $code, $output);
        self::assertFileExists($this->tmp . '/build/ai-evals.worker-3.json');
        self::assertFileDoesNotExist($this->tmp . '/build/ai-evals.json');

        $this->writeConfig('<parameter name="cassettesPath" value="cassettes"/><parameter name="reportPath" value="build/report"/>');
        $this->runPhpunit(['TEST_TOKEN' => '3']);
        self::assertFileExists($this->tmp . '/build/report.worker-3', 'a path without an extension gets the suffix at the end');
    }

    public function testPruneRemovesInteractionsNoTestUsed(): void
    {
        $this->writeConfig('<parameter name="cassettesPath" value="cassettes"/>');
        [$code, $output] = $this->runPhpunit();
        self::assertSame(0, $code, $output);
        self::assertStringNotContainsString('pruned', $output);

        $files = glob($this->tmp . '/cassettes/*.json') ?: [];
        self::assertCount(1, $files);
        $cassette = json_decode((string) file_get_contents($files[0]), true);
        self::assertIsArray($cassette);
        self::assertIsArray($cassette['interactions']);
        $cassette['interactions'][] = ['kind' => 'agent_call', 'semantic_hash' => 'stale', 'occurrence_index' => 0];
        file_put_contents($files[0], json_encode($cassette));

        [$code, $output] = $this->runPhpunit(['AI_EVAL_PRUNE' => 'yes']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('pruned 1 unused interaction(s) from 1 cassette(s)', $output);
        $json = (string) file_get_contents($files[0]);
        self::assertStringNotContainsString('stale', $json);
        self::assertSame(2, substr_count($json, '"kind": "agent_call"'));
    }

    public function testPruneIsSkippedWhenTheRunFails(): void
    {
        $this->writeConfig('<parameter name="cassettesPath" value="cassettes"/>');
        $this->runPhpunit();
        $file = (glob($this->tmp . '/cassettes/*.json') ?: [''])[0];
        $cassette = Payload::map(json_decode((string) file_get_contents($file), true));
        $cassette['interactions'] = [...Payload::maps($cassette['interactions'] ?? null), ['kind' => 'agent_call', 'semantic_hash' => 'stale', 'occurrence_index' => 0]];
        file_put_contents($file, json_encode($cassette));

        [$code, $output] = $this->runPhpunit(['AI_EVAL_PRUNE' => '1', 'AI_EVAL_FIXTURE_FAIL' => '1']);

        self::assertNotSame(0, $code);
        self::assertStringNotContainsString('pruned', $output);
        self::assertStringContainsString('stale', (string) file_get_contents($file), 'a red run must not delete recordings');
    }

    public function testInvalidModeParameterIsReported(): void
    {
        $this->writeConfig('<parameter name="mode" value="bogus"/>');

        [, $output] = $this->runPhpunit();

        self::assertStringContainsString('Invalid AiEvalsExtension "mode" parameter "bogus"', $output);
    }

    public function testInvalidBaselineToleranceIsReported(): void
    {
        $this->writeConfig('<parameter name="baselinePath" value="b.json"/><parameter name="baselineTolerance" value="5%"/>');

        [, $output] = $this->runPhpunit();

        self::assertStringContainsString('Invalid AiEvalsExtension "baselineTolerance" parameter "5%"', $output);
    }
}
