<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr;

/**
 * @internal
 */
final class SecretScrubber
{
    public const REDACTED = '[REDACTED]';

    private const HEADERS = [
        'authorization', 'proxy-authorization', 'x-api-key', 'api-key', 'openai-organization', 'x-goog-api-key',
        'cookie', 'set-cookie', 'x-amz-security-token',
        // Response headers that identify the account behind the key.
        'anthropic-organization-id', 'openai-project',
    ];

    /** Form fields and JSON keys whose values are credentials. */
    private const KEYS = [
        'api_key', 'apikey', 'access_token', 'refresh_token', 'id_token', 'token', 'client_secret', 'password', 'secret',
    ];

    /** Name endings such as `x-portkey-api-key` or `session_token`; `token_count` and rate-limit `tokens` don't match. */
    private const SECRET_WORDS = 'api[-_]?key|access[-_]?key|private[-_]?key|auth|authorization|token|secret|password|passwd|signature';

    private const SECRET_KEY = '/(?:^|[-_])(?:' . self::SECRET_WORDS . '|credentials?|client[-_]assertion)$/i';

    /** Headers add `key` (e.g. `ocp-apim-subscription-key`) and `cookie`, and leave out CORS `*-credentials`. */
    private const SECRET_HEADER = '/(?:^|[-_])(?:' . self::SECRET_WORDS . '|key|cookie)$/i';

    /**
     * Credentials recognised by their shape wherever they appear: in prompts, bodies, URLs, header values and traces.
     * Each match is replaced whole, except `Bearer ` and `Basic ` keep their scheme.
     */
    private const TOKEN_PATTERNS = [
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]{8,}=*/',
        '/\bsk-(?:ant-|proj-|svcacct-|or-v1-)?[A-Za-z0-9_-]{20,}/',             // OpenAI, Anthropic, OpenRouter
        '/\b[rsp]k_(?:live|test)_[A-Za-z0-9]{16,}/',                           // Stripe
        '/\bAIza[0-9A-Za-z_-]{35}/',                                           // Google API keys
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',                                     // AWS access key ids
        '/\b(?:gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{22,})/',     // GitHub
        '/\bxox[abposr]-[A-Za-z0-9-]{10,}/',                                   // Slack
        '/\b(?:hf|gsk|xai|pplx)[_-][A-Za-z0-9]{30,}/',                         // Hugging Face, Groq, xAI, Perplexity
        '/\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', // JWTs
    ];

    /** @var list<string> */
    private readonly array $headers;

    /** @var list<string> */
    private readonly array $patterns;

    /** @var list<string> */
    private readonly array $keys;

    /** @var list<string> Gemini passes its API key as `?key=`; too generic a name to redact inside bodies. */
    private readonly array $queryKeys;

    /**
     * @param list<string> $extraHeaders Header names redacted on top of the built-in list (case-insensitive)
     * @param list<string> $extraKeys Query, form and JSON key names redacted on top of the built-in list (case-insensitive)
     * @param list<string> $extraPatterns PCRE patterns for credential values, redacted wherever they appear
     */
    public function __construct(array $extraHeaders = [], array $extraKeys = [], array $extraPatterns = [])
    {
        foreach ($extraPatterns as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new \InvalidArgumentException(sprintf('Invalid redaction pattern: %s', $pattern));
            }
        }
        $this->patterns = [...self::TOKEN_PATTERNS, ...$extraPatterns];
        $this->headers = [...self::HEADERS, ...array_map(strtolower(...), $extraHeaders)];
        $this->keys = [...self::KEYS, ...array_map(strtolower(...), $extraKeys)];
        $this->queryKeys = ['key', ...$this->keys];
    }

    /**
     * @param array<string|array<string>> $headers
     * @return array<string, list<string>>
     */
    public function scrubHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $name = (string) $name;
            $out[$name] = in_array(strtolower($name), $this->headers, true) || preg_match(self::SECRET_HEADER, $name) === 1
                ? [self::REDACTED]
                : array_map(fn(mixed $v): string => $this->scrubText(strval($v)), array_values((array) $values));
        }

        return $out;
    }

    public function scrubUri(string $uri): string
    {
        $uri = (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/?\#@]*@#i', '$1' . self::REDACTED . '@', $uri);
        $queryStart = strpos($uri, '?');
        if ($queryStart === false) {
            return $uri;
        }
        $fragmentStart = strpos($uri, '#', $queryStart);
        $query = substr($uri, $queryStart + 1, $fragmentStart === false ? null : $fragmentStart - $queryStart - 1);
        $fragment = $fragmentStart === false ? '' : substr($uri, $fragmentStart);

        return substr($uri, 0, $queryStart) . '?' . $this->scrubQuery($query, $this->queryKeys) . $fragment;
    }

    /**
     * Redacts credentials in a JSON or form-encoded body; any other text is returned unchanged.
     */
    public function scrubBody(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $scrubbed = $this->scrubData($decoded);

            return $scrubbed === $decoded ? $body : (string) json_encode($scrubbed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return preg_match('/^[^\s=&]+=[^\s&]*(?:&[^\s=&]*=?[^\s&]*)*$/', $body) === 1 ? $this->scrubQuery($body, $this->keys) : $body;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function scrubData(array $data): array
    {
        foreach ($data as $key => $value) {
            // Flags like `auth: false` are never credentials.
            if (is_string($key) && self::isSecretKey($key, $this->keys) && !is_array($value) && !is_bool($value) && $value !== null) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->scrubData($value);
            }
        }

        return $data;
    }

    /**
     * Redacts credential-shaped values (API keys, bearer tokens, JWTs) in every string of $value, recursively.
     *
     * @template T of mixed
     * @param T $value
     * @return (T is string ? string : (T is array ? array<mixed> : T))
     */
    public function scrubValues(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->scrubText($value);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->scrubValues($v);
            }
        }

        return $value;
    }

    /**
     * scrubValues() for a string-keyed map, keeping its type.
     *
     * @param array<string, mixed> $map
     * @return array<string, mixed>
     */
    public function scrubMap(array $map): array
    {
        foreach ($map as $k => $v) {
            $map[$k] = $this->scrubValues($v);
        }

        return $map;
    }

    public function scrubText(string $text): string
    {
        foreach ($this->patterns as $pattern) {
            $text = (string) preg_replace_callback(
                $pattern,
                static fn(array $m): string => in_array($m[1] ?? '', ['Bearer', 'Basic'], true) ? $m[1] . ' ' . self::REDACTED : self::REDACTED,
                $text,
            );
        }

        return $text;
    }

    /**
     * @param list<string> $keys
     */
    private function scrubQuery(string $query, array $keys): string
    {
        return implode('&', array_map(static function (string $pair) use ($keys): string {
            [$key] = explode('=', $pair, 2);

            return self::isSecretKey(urldecode($key), $keys) ? $key . '=' . self::REDACTED : $pair;
        }, explode('&', $query)));
    }

    /**
     * @param list<string> $keys
     */
    private static function isSecretKey(string $name, array $keys): bool
    {
        return in_array(strtolower($name), $keys, true) || preg_match(self::SECRET_KEY, $name) === 1;
    }
}
