<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr;

use InvalidArgumentException;

/**
 * Which request fields decide whether a recording matches. Fields are dot paths into the normalized request
 * (`model`, `messages.*.name`, `metadata.request_id`, for non-LLM traffic `body.json.timestamp` or `uri`), and `*`
 * stands for any key or list index at that level.
 *
 * Set them globally with the extension parameters of the same names, and per class or test with #[AiVcr]; the three
 * levels add up.
 */
final class MatchingRules
{
    public const DEFAULT_PLACEHOLDER = '<NORMALIZED>';

    /** @var list<string> */
    public readonly array $ignoreFields;

    /** @var array<string, string> path => placeholder */
    public readonly array $normalizeFields;

    /** @var list<string> lowercase header names */
    public readonly array $matchHeaders;

    /** @var list<string> */
    public readonly array $matchFields;

    /**
     * @param list<string> $ignoreFields Removed before hashing, e.g. `model` to reuse recordings across models
     * @param array<int|string, string> $normalizeFields Path => placeholder, or a bare path for `<NORMALIZED>`: the field
     *                                                   still has to be present, but any value matches
     * @param list<string> $matchHeaders Request headers whose values join the hash (none by default); they appear as
     *                                   `headers.<lowercase name>` for ignoreFields and normalizeFields
     * @param list<string> $matchFields Top-level fields hashed on top of the built-in semantic ones, e.g. `temperature`
     */
    public function __construct(
        array $ignoreFields = [],
        array $normalizeFields = [],
        array $matchHeaders = [],
        array $matchFields = [],
    ) {
        $this->ignoreFields = array_values(array_unique(array_map(self::path(...), $ignoreFields)));
        $normalized = [];
        foreach ($normalizeFields as $key => $value) {
            [$path, $placeholder] = is_int($key) ? [$value, self::DEFAULT_PLACEHOLDER] : [$key, $value];
            $normalized[self::path($path)] = $placeholder;
        }
        $this->normalizeFields = $normalized;
        $this->matchHeaders = array_values(array_unique(array_map(static fn(string $h): string => strtolower(trim($h)), $matchHeaders)));
        $this->matchFields = array_values(array_unique(array_map(trim(...), $matchFields)));
    }

    /**
     * $other's lists are added to these; for a path normalized in both, $other's placeholder wins.
     */
    public function merge(self $other): self
    {
        return new self(
            [...$this->ignoreFields, ...$other->ignoreFields],
            array_merge($this->normalizeFields, $other->normalizeFields),
            [...$this->matchHeaders, ...$other->matchHeaders],
            [...$this->matchFields, ...$other->matchFields],
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload): array
    {
        foreach ($this->normalizeFields as $path => $placeholder) {
            $payload = self::walk($payload, explode('.', $path), static fn(): string => $placeholder);
        }
        foreach ($this->ignoreFields as $path) {
            $payload = self::walk($payload, explode('.', $path), null);
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param array<array-key, string|list<string>> $headers PSR-7 style headers
     * @return array<string, string> the matched headers, lowercase names, values joined with `, `
     */
    public function pickHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $lower = strtolower((string) $name);
            if (in_array($lower, $this->matchHeaders, true)) {
                $out[$lower] = implode(', ', (array) $values);
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Applies $change at every node $segments reaches; a null $change removes the node. Lists stay lists.
     *
     * @param array<mixed> $node
     * @param list<string> $segments
     * @param (callable(mixed): mixed)|null $change
     * @return array<mixed>
     */
    private static function walk(array $node, array $segments, ?callable $change): array
    {
        $segment = (string) array_shift($segments);
        $keys = $segment === '*' ? array_keys($node) : (array_key_exists($segment, $node) ? [$segment] : []);
        $wasList = array_is_list($node);
        foreach ($keys as $key) {
            if ($segments === []) {
                if ($change === null) {
                    unset($node[$key]);
                } else {
                    $node[$key] = $change($node[$key]);
                }
            } elseif (is_array($node[$key])) {
                $node[$key] = self::walk($node[$key], $segments, $change);
            }
        }

        return $wasList ? array_values($node) : $node;
    }

    private static function path(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '.') || str_ends_with($path, '.')) {
            throw new InvalidArgumentException(sprintf('Invalid request field path "%s"; use dot paths such as "messages.*.content".', $path));
        }

        return $path;
    }
}
