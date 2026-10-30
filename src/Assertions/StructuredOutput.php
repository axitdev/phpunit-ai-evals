<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Assertions;

use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Reads a model's JSON answer for the structured-output assertions, failing with a message that says what is wrong
 * with the output rather than a bare type error.
 *
 * @internal
 */
final class StructuredOutput
{
    private const PREVIEW = 200;

    /**
     * The raw text: a string as given, an interaction's assistant content, or (null) the last interaction's.
     */
    public static function text(string|AiInteraction|null $output): string
    {
        if (is_string($output)) {
            return $output;
        }
        $output ??= AiTraceCollector::current()->last()
            ?? throw new AssertionFailedError('No AI interaction was recorded in this test, so there is no model output to check. Pass the output, or send the request through wrapHttpClient().');

        return $output->assistantContent;
    }

    /**
     * @throws AssertionFailedError when the output isn't valid JSON
     */
    public static function decode(string|AiInteraction|null $output): mixed
    {
        $text = self::text($output);
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        throw new AssertionFailedError(self::invalidJsonMessage($text, json_last_error_msg()));
    }

    public static function invalidJsonMessage(string $text, string $error): string
    {
        $trimmed = trim($text);
        $hint = match (true) {
            $trimmed === '' => ' The output is empty; was the reply a tool call or a refusal?',
            preg_match('/^```/', $trimmed) === 1 => ' It is wrapped in a Markdown code fence; ask for raw JSON, or use the provider\'s structured-output mode.',
            !str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '[') && preg_match('/[{\[]/', $trimmed) === 1 => ' There is text around the JSON; ask for JSON only, or use the provider\'s structured-output mode.',
            default => '',
        };

        return sprintf('The model output is not valid JSON (%s).%s Output: %s', $error, $hint, self::preview($text));
    }

    /**
     * The value at a dot path (`scores.relevance`, `items.0.label`) or JSON Pointer (`/scores/relevance`).
     *
     * @throws AssertionFailedError when a segment is missing
     */
    public static function field(mixed $data, string $path): mixed
    {
        $segments = str_starts_with($path, '/')
            ? array_map(static fn(string $s): string => str_replace(['~1', '~0'], ['/', '~'], $s), array_slice(explode('/', $path), 1))
            : explode('.', $path);
        $node = $data;
        $walked = [];
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                $where = $walked === [] ? 'the output' : '`' . implode('.', $walked) . '`';
                $keys = is_array($node) ? implode(', ', array_map(strval(...), array_keys($node))) : get_debug_type($node);

                throw new AssertionFailedError(sprintf(
                    'Field `%s` is missing from the model output: %s has no `%s` (it has: %s).',
                    $path,
                    $where,
                    $segment,
                    $keys === '' ? 'nothing' : $keys,
                ));
            }
            $node = $node[$segment];
            $walked[] = $segment;
        }

        return $node;
    }

    /**
     * @throws AssertionFailedError when the field isn't a JSON number
     */
    public static function number(mixed $data, string $path): float
    {
        $value = self::field($data, $path);
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        throw new AssertionFailedError(sprintf(
            'Field `%s` of the model output is not a number: %s%s.',
            $path,
            self::preview((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            is_string($value) && is_numeric($value) ? ' (a numeric string; ask for a JSON number, e.g. with "type": "number" in the schema)' : '',
        ));
    }

    public static function preview(string $text): string
    {
        $flat = (string) preg_replace('/\s+/u', ' ', $text);

        return '"' . (mb_strlen($flat) > self::PREVIEW ? mb_substr($flat, 0, self::PREVIEW - 3) . '...' : $flat) . '"';
    }
}
