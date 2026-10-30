<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\PhpUnit;

use Axitdev\PhpUnitAiEvals\PhpUnit\Subscribers\ExecutionFinishedSummarySubscriber;
use Axitdev\PhpUnitAiEvals\PhpUnit\Subscribers\PruneCassettesSubscriber;
use Axitdev\PhpUnitAiEvals\PhpUnit\Subscribers\ReportSubscriber;
use Axitdev\PhpUnitAiEvals\PhpUnit\Subscribers\TestFinishedSubscriber;
use Axitdev\PhpUnitAiEvals\PhpUnit\Subscribers\TestPreparationStartedSubscriber;
use Axitdev\PhpUnitAiEvals\Vcr\MatchingRules;
use Axitdev\PhpUnitAiEvals\Vcr\SecretScrubber;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use InvalidArgumentException;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class AiEvalsExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        TestContextRegistry::reset();
        TestContextRegistry::configure(
            $parameters->has('cassettesPath') ? $this->resolvePath($parameters->get('cassettesPath'), $configuration) : null,
            $parameters->has('mode') ? VcrMode::parse($parameters->get('mode'), 'AiEvalsExtension "mode" parameter') : null,
            new SecretScrubber(
                $this->parseList($parameters, 'redactHeaders'),
                $this->parseList($parameters, 'redactKeys'),
                // Patterns may contain commas, so they are separated by whitespace; XML turns newlines in attribute values
                // into spaces anyway. Write a space inside a pattern as \s or \x20.
                $this->parseList($parameters, 'redactPatterns', '/\s+/'),
            ),
            new MatchingRules(
                $this->parseList($parameters, 'ignoreFields'),
                $this->parseNormalizeFields($parameters),
                $this->parseList($parameters, 'matchHeaders'),
                $this->parseList($parameters, 'matchFields'),
            ),
        );

        $facade->registerSubscribers(
            new TestPreparationStartedSubscriber(),
            new TestFinishedSubscriber(),
            new ExecutionFinishedSummarySubscriber(),
        );
        if ($parameters->has('reportPath') || $parameters->has('baselinePath')) {
            $facade->registerSubscriber(new ReportSubscriber(
                $parameters->has('reportPath') ? $this->perWorker($this->resolvePath($parameters->get('reportPath'), $configuration)) : null,
                $parameters->has('baselinePath') ? $this->resolvePath($parameters->get('baselinePath'), $configuration) : null,
                $parameters->has('baselineTolerance') ? $this->tolerance($parameters->get('baselineTolerance')) : 0.05,
            ));
        }
        if (VcrMode::flag('AI_EVAL_PRUNE')) {
            $facade->registerSubscriber(new PruneCassettesSubscriber());
        }
    }

    private function tolerance(string $value): float
    {
        if (!is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException(sprintf('Invalid AiEvalsExtension "baselineTolerance" parameter "%s"; expected a number >= 0, such as 0.05.', $value));
        }

        return (float) $value;
    }

    /**
     * @return list<string>
     */
    private function parseList(ParameterCollection $parameters, string $name, string $separator = '/,/'): array
    {
        if (!$parameters->has($name)) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), preg_split($separator, $parameters->get($name)) ?: []),
            static fn(string $v): bool => $v !== '',
        ));
    }

    /**
     * `normalizeFields="metadata.request_id, user=<USER>"`: a bare path gets the default placeholder.
     *
     * @return array<int|string, string>
     */
    private function parseNormalizeFields(ParameterCollection $parameters): array
    {
        $out = [];
        foreach ($this->parseList($parameters, 'normalizeFields') as $item) {
            if (str_contains($item, '=')) {
                [$path, $placeholder] = array_map(trim(...), explode('=', $item, 2));
                $out[$path] = $placeholder;
            } else {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * ParaTest runs one PHPUnit process per worker and sets TEST_TOKEN; each worker gets its own report file
     * (`ai-evals.json` becomes `ai-evals.worker-2.json`) instead of overwriting a shared one.
     */
    private function perWorker(string $path): string
    {
        $token = getenv('TEST_TOKEN');
        if (!is_string($token) || $token === '') {
            return $path;
        }
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return substr($path, 0, $extension === '' ? null : -strlen($extension) - 1) . '.worker-' . $token . ($extension === '' ? '' : '.' . $extension);
    }

    private function resolvePath(string $path, Configuration $configuration): string
    {
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }
        $base = $configuration->hasConfigurationFile() ? dirname($configuration->configurationFile()) : (string) getcwd();

        return $base . '/' . $path;
    }
}
