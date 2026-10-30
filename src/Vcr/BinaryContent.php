<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr;

/**
 * Stands in for binary request content (images, audio, uploaded files) in hashes and cassettes: a content hash, the
 * size in bytes and the MIME type, never the bytes. The same file hashes the same whether it was sent as a data URI,
 * a base64 field or a multipart part.
 *
 * @internal
 */
final class BinaryContent
{
    public const DEFAULT_MIME = 'application/octet-stream';

    /** A `data:` URI with a base64 payload, standard or URL-safe alphabet. */
    public const DATA_URI = '#data:([\w.+-]+/[\w.+-]+)(?:;[\w.+-]+=[\w.+-]+)*;base64,([A-Za-z0-9+/=_-]+)#';

    /** Bare base64 shorter than this is left alone: it is more likely an id or a short token than a file. */
    private const MIN_BASE64_LENGTH = 64;

    /** Sibling keys that name the MIME type of a base64 `data` field (Anthropic, Gemini, generic). */
    private const MIME_KEYS = ['media_type', 'mediaType', 'mime_type', 'mimeType', 'content_type', 'contentType'];

    /**
     * @return array{sha256: string, size: int, mime: string}
     */
    public static function describe(string $bytes, ?string $mime = null): array
    {
        return [
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'mime' => $mime !== null && $mime !== '' ? strtolower($mime) : self::detectMime($bytes),
        ];
    }

    /**
     * The inline form used inside strings, e.g. a data URI in a message: `<binary sha256=… size=1234 mime=image/png>`.
     */
    public static function placeholder(string $bytes, ?string $mime = null): string
    {
        $d = self::describe($bytes, $mime);

        return sprintf('<binary sha256=%s size=%d mime=%s>', $d['sha256'], $d['size'], $d['mime']);
    }

    /**
     * Replaces every base64 data URI in $text with its placeholder.
     */
    public static function replaceDataUris(string $text): string
    {
        if (!str_contains($text, 'data:')) {
            return $text;
        }

        return (string) preg_replace_callback(
            self::DATA_URI,
            static fn(array $m): string => self::placeholder(self::decode($m[2]), $m[1]),
            $text,
        );
    }

    /**
     * A `{"data": "<base64>", "media_type": "image/png"}`-like node (Anthropic `source`, Gemini `inlineData`, OpenAI
     * `input_audio`) with the bytes swapped for their placeholder; any other node is returned unchanged.
     *
     * @param array<mixed> $node
     * @return array<mixed>
     */
    public static function replaceBase64Field(array $node): array
    {
        $data = $node['data'] ?? null;
        if (!is_string($data) || str_starts_with($data, '<binary ')) {
            return $node;
        }
        $explicit = ($node['type'] ?? null) === 'base64';
        if (!$explicit && !self::looksLikeBase64($data)) {
            return $node;
        }

        $mime = null;
        foreach (self::MIME_KEYS as $key) {
            if (is_string($node[$key] ?? null)) {
                $mime = $node[$key];
                break;
            }
        }
        // OpenAI input_audio names only the container format, e.g. `"format": "wav"`.
        if ($mime === null && is_string($node['format'] ?? null) && preg_match('/^[a-z0-9]+$/', $node['format']) === 1) {
            $mime = 'audio/' . $node['format'];
        }
        $node['data'] = self::placeholder(self::decode($data), $mime);

        return $node;
    }

    public static function isBinary(string $bytes): bool
    {
        return !mb_check_encoding($bytes, 'UTF-8') || preg_match('/[\x00-\x08\x0E-\x1F]/', $bytes) === 1;
    }

    private static function looksLikeBase64(string $value): bool
    {
        return strlen($value) >= self::MIN_BASE64_LENGTH && preg_match('#^[A-Za-z0-9+/_-]+={0,2}$#', $value) === 1;
    }

    private static function decode(string $base64): string
    {
        $decoded = base64_decode(strtr($base64, '-_', '+/'), true);

        // Not valid base64 after all: describe the text itself, so the hash still follows the content.
        return $decoded === false ? $base64 : $decoded;
    }

    private static function detectMime(string $bytes): string
    {
        if (class_exists(\finfo::class)) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return self::DEFAULT_MIME;
    }
}
