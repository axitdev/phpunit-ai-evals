<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr;

/**
 * Turns a non-LLM request body into what is hashed and written to the cassette: JSON is decoded so fields can be
 * matched and diffed by name, multipart uploads become their fields plus a hash/size/MIME per file, and other binary
 * bodies become a single descriptor. Bytes never reach the cassette.
 *
 * @internal
 */
final class RequestBody
{
    /**
     * @return string|array<mixed>
     */
    public static function describe(string $body, string $contentType): string|array
    {
        if ($body === '') {
            return '';
        }
        if (preg_match('#^\s*multipart/#i', $contentType) === 1) {
            $boundary = self::boundary($contentType);
            if ($boundary !== null) {
                $parts = self::multipart($body, $boundary);
                if ($parts !== null) {
                    return ['multipart' => $parts];
                }
            }
        }
        if (BinaryContent::isBinary($body)) {
            return ['binary' => BinaryContent::describe($body, self::mime($contentType))];
        }
        $decoded = json_decode($body, true);
        if (is_array($decoded) && $decoded !== [] && self::isJson($contentType, $body)) {
            return ['json' => $decoded];
        }

        return $body;
    }

    private static function isJson(string $contentType, string $body): bool
    {
        $type = self::mime($contentType);

        return $type === null || str_contains($type, 'json') || in_array(ltrim($body)[0] ?? '', ['{', '['], true);
    }

    private static function mime(string $contentType): ?string
    {
        $type = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $type === '' ? null : $type;
    }

    private static function boundary(string $contentType): ?string
    {
        return preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $m) === 1 ? ($m[1] !== '' ? $m[1] : $m[2]) : null;
    }

    /**
     * Fields keyed by name; a name sent more than once keeps every value, in order. The boundary itself is random per
     * request, so it is left out.
     *
     * @return array<string, mixed>|null null when the body isn't valid multipart for this boundary
     */
    private static function multipart(string $body, string $boundary): ?array
    {
        $delimiter = '--' . $boundary;
        $chunks = explode($delimiter, $body);
        if (count($chunks) < 3) {
            return null;
        }
        array_shift($chunks); // preamble
        $fields = [];
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--')) {
                break; // closing delimiter
            }
            $chunk = (string) preg_replace('/^\r?\n/', '', $chunk);
            $chunk = (string) preg_replace('/\r?\n$/', '', $chunk);
            $split = preg_split('/\r?\n\r?\n/', $chunk, 2);
            if ($split === false || count($split) < 2) {
                continue;
            }
            [$rawHeaders, $content] = $split;
            $headers = [];
            foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }
            $disposition = $headers['content-disposition'] ?? '';
            $name = self::param($disposition, 'name') ?? '';
            $filename = self::param($disposition, 'filename');
            $type = isset($headers['content-type']) ? self::mime($headers['content-type']) : null;

            if ($filename === null && !BinaryContent::isBinary($content) && ($type === null || str_starts_with($type, 'text/') || str_contains($type, 'json'))) {
                $value = $content;
            } else {
                $value = ($filename !== null ? ['filename' => $filename] : []) + BinaryContent::describe($content, $type);
            }
            if (array_key_exists($name, $fields)) {
                $existing = $fields[$name];
                $fields[$name] = is_array($existing) && array_is_list($existing) ? [...$existing, $value] : [$existing, $value];
            } else {
                $fields[$name] = $value;
            }
        }

        return $fields === [] ? null : $fields;
    }

    private static function param(string $header, string $name): ?string
    {
        // `name=` must not match inside `filename=`.
        $pattern = '/(?:^|;)\s*' . preg_quote($name, '/') . '\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]*))/i';

        return preg_match($pattern, $header, $m) === 1 ? stripslashes($m[1] !== '' ? $m[1] : ($m[2] ?? '')) : null;
    }
}
