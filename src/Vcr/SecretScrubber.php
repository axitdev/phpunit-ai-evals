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

    /** @var list<string> */
    private readonly array $headers;

    /** @var list<string> */
    private readonly array $keys;

    /** @var list<string> Gemini passes its API key as `?key=`; too generic a name to redact inside bodies. */
    private readonly array $queryKeys;

    /**
     * @param list<string> $extraHeaders Header names redacted on top of the built-in list (case-insensitive)
     * @param list<string> $extraKeys Query, form and JSON key names redacted on top of the built-in list (case-insensitive)
     */
    public function __construct(array $extraHeaders = [], array $extraKeys = [])
    {
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
                : array_map(strval(...), array_values((array) $values));
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
