<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr;

use InvalidArgumentException;

/**
 * @internal
 */
final class PromptNormalizer
{
    private const SEMANTIC_KEYS = [
        'model', 'messages', 'contents', 'system', 'system_instruction', 'systemInstruction', 'input', 'prompt',
        'tools', 'tool_choice', 'tool_config', 'toolConfig', 'response_format', 'format',
        // `stream` changes the response wire format; the next three are OpenAI Responses API fields.
        'stream', 'instructions', 'previous_response_id', 'text',
        // Reasoning and structured-output settings change what comes back (Anthropic, OpenAI, Ollama).
        'thinking', 'reasoning', 'reasoning_effort', 'output_config', 'output_format', 'think',
        // System One models (TypeSafe Jev): the state to evaluate and the typed questions about it.
        'state', 'questions',
    ];

    /** Gemini mixes sampling settings into generationConfig; only these change what comes back. */
    private const GEMINI_SEMANTIC_CONFIG = [
        'responseMimeType', 'responseSchema', 'responseJsonSchema', 'responseModalities', 'thinkingConfig',
    ];

    /** Not inside a longer token such as a phone number `555-1234-56-78`. */
    private const ISO_DATE = '/(?<![\w-])\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?(?![\w-])/';

    private const UUID = '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i';

    private const BASE64_DATA_URI = '#data:([\w.+-]+/[\w.+-]+);base64,[A-Za-z0-9+/=_-]+#';

    /**
     * @param list<string> $ignorePatterns Custom PCRE patterns replaced with <IGNORED>
     * @param bool $ignoreDatesAndUuids Off when dates or UUIDs are what the prompt is about, so they stay distinct
     */
    public function __construct(
        private readonly array $ignorePatterns = [],
        private readonly bool $ignoreDatesAndUuids = true,
    ) {}

    /**
     * @param array<mixed> $payload Decoded request body
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        $semantic = [];
        foreach (self::SEMANTIC_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                $semantic[$key] = $payload[$key];
            }
        }
        if (($semantic['stream'] ?? null) === false) {
            unset($semantic['stream']);
        }
        $config = array_intersect_key(
            is_array($payload['generationConfig'] ?? null) ? $payload['generationConfig'] : [],
            array_flip(self::GEMINI_SEMANTIC_CONFIG),
        );
        if ($config !== []) {
            $semantic['generationConfig'] = $config;
        }

        $result = $this->canonicalize($semantic);
        assert(is_array($result));

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * A judge's cache payload is built by the judge itself, so every key counts; only the volatile values go.
     *
     * @param array<mixed> $payload
     * @return array<string, mixed>
     */
    public function normalizeJudge(array $payload): array
    {
        $result = $this->canonicalize($payload);
        assert(is_array($result));

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * Non-LLM traffic: the body is kept whole, minus the same volatile values as prompts.
     *
     * @return array{method: string, uri: string, body: string}
     */
    public function normalizeRaw(string $method, string $uri, string $body): array
    {
        return ['method' => $method, 'uri' => $this->scrubString($uri), 'body' => $this->scrubString($body)];
    }

    /**
     * @param array<string, mixed> $normalized
     */
    public function hash(array $normalized): string
    {
        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value, ?string $key = null): mixed
    {
        if (is_string($value)) {
            if ($key === 'arguments') {
                $value = $this->canonicalizeEncodedJson($value);
            }

            return $this->scrubString($value);
        }
        if (!is_array($value)) {
            return $value;
        }

        if (($value['type'] ?? null) === 'thinking') {
            unset($value['signature']);
        }
        if (($value['type'] ?? null) === 'base64' && is_string($value['data'] ?? null)) {
            $value['data'] = '<BASE64_SHA256:' . hash('sha256', $value['data']) . '>';
        }

        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->canonicalize($v, (string) $k);
        }
        if (!array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    private function canonicalizeEncodedJson(string $value): string
    {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return $value;
        }

        return json_encode($this->canonicalize($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $value;
    }

    private function scrubString(string $text): string
    {
        $text = (string) preg_replace_callback(
            self::BASE64_DATA_URI,
            static fn(array $m): string => '<BASE64_SHA256:' . hash('sha256', $m[0]) . '>',
            $text,
        );
        if ($this->ignoreDatesAndUuids) {
            $text = $this->replace(self::UUID, '<UUID>', $text);
            $text = $this->replace(self::ISO_DATE, '<TIMESTAMP>', $text);
        }
        foreach ($this->ignorePatterns as $pattern) {
            $text = $this->replace($pattern, '<IGNORED>', $text);
        }

        return $text;
    }

    private function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);
        if ($result === null) {
            throw new InvalidArgumentException(sprintf('Invalid or failing regex pattern: %s', $pattern));
        }

        return $result;
    }
}
