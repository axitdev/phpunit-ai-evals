<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr;

use Axitdev\PhpUnitAiEvals\Support\Payload;

/**
 * Condenses streamed bodies (`text/event-stream` SSE, Ollama's `application/x-ndjson` or AWS's binary
 * `application/vnd.amazon.eventstream`) into JSON chunks for the cassette, merges chunks back into a non-streaming
 * response shape for the payload parsers, and re-renders the original wire format for replay.
 *
 * @internal
 */
final class StreamRebuilder
{
    /** Providers whose SSE events carry an `event:` line named after the chunk's `type`. */
    private const TYPED_EVENTS = ['anthropic', 'openai-responses'];

    /** Providers whose SSE stream ends without a `data: [DONE]` sentinel. */
    private const NO_DONE = ['anthropic', 'openai-responses', 'gemini'];

    public static function isStream(string $contentType): bool
    {
        $contentType = strtolower($contentType);

        return str_contains($contentType, 'text/event-stream')
            || str_contains($contentType, 'application/x-ndjson')
            || str_contains($contentType, 'application/vnd.amazon.eventstream');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $raw, string $contentType = ''): array
    {
        if (str_contains(strtolower($contentType), 'application/vnd.amazon.eventstream')) {
            return self::parseEventStream($raw);
        }
        $raw = str_replace("\r\n", "\n", $raw);
        $events = preg_match('/^(data|event|id|retry|:)/m', $raw) === 1
            ? array_map(self::sseData(...), explode("\n\n", $raw))
            : explode("\n", $raw);

        $chunks = [];
        foreach ($events as $json) {
            $decoded = $json === '' || $json === '[DONE]' ? null : json_decode($json, true);
            if (is_array($decoded)) {
                $chunks[] = Payload::map($decoded);
            }
        }

        return $chunks;
    }

    /**
     * Anthropic and the Responses API send an `error` event, OpenAI, Gemini and Ollama an `error` object, and Bedrock
     * an exception frame, all inside an HTTP 200 stream.
     *
     * @param list<array<string, mixed>> $chunks
     */
    public static function hasError(array $chunks): bool
    {
        foreach ($chunks as $chunk) {
            if (($chunk['type'] ?? null) === 'error' || isset($chunk['error']) || isset($chunk['_exception'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $chunks
     */
    public function render(array $chunks, string $provider): string
    {
        if ($provider === 'bedrock') {
            return implode('', array_map(self::eventStreamFrame(...), $chunks));
        }
        $out = '';
        foreach ($chunks as $chunk) {
            $json = json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($provider === 'ollama') {
                $out .= $json . "\n";
                continue;
            }
            $event = in_array($provider, self::TYPED_EVENTS, true) && is_string($chunk['type'] ?? null) ? 'event: ' . $chunk['type'] . "\n" : '';
            $out .= $event . 'data: ' . $json . "\n\n";
        }

        return $provider === 'ollama' || in_array($provider, self::NO_DONE, true) ? $out : $out . "data: [DONE]\n\n";
    }

    /**
     * @param list<array<string, mixed>> $chunks
     * @return array<string, mixed>
     */
    public function merge(array $chunks, string $provider): array
    {
        return match ($provider) {
            'openai' => $this->mergeOpenAi($chunks),
            'openai-responses' => $this->mergeOpenAiResponses($chunks),
            'anthropic' => $this->mergeAnthropic($chunks),
            'gemini' => $this->mergeGemini($chunks),
            'ollama' => $this->mergeOllama($chunks),
            'bedrock' => $this->mergeBedrock($chunks),
            default => $chunks === [] ? [] : $chunks[array_key_last($chunks)],
        };
    }

    /**
     * AWS event-stream frames: total length, headers length and prelude CRC (uint32 each), headers, payload, message
     * CRC. Each JSON payload becomes a chunk, with the `:event-type` header kept as `_event`.
     *
     * @return list<array<string, mixed>>
     */
    private static function parseEventStream(string $raw): array
    {
        $chunks = [];
        for ($offset = 0; $offset + 16 <= strlen($raw);) {
            $prelude = unpack('Ntotal/Nheaders', $raw, $offset);
            $total = Payload::int($prelude['total'] ?? null);
            $headersLength = Payload::int($prelude['headers'] ?? null);
            if ($total < 16 || $offset + $total > strlen($raw)) {
                break;
            }
            $headers = self::eventStreamHeaders(substr($raw, $offset + 12, $headersLength));
            $payload = json_decode(substr($raw, $offset + 12 + $headersLength, $total - $headersLength - 16), true);
            if (is_array($payload)) {
                $chunks[] = ($headers[':message-type'] ?? '') === 'exception'
                    ? ['_exception' => $headers[':exception-type'] ?? 'exception'] + Payload::map($payload)
                    : ['_event' => $headers[':event-type'] ?? ''] + Payload::map($payload);
            }
            $offset += $total;
        }

        return $chunks;
    }

    /**
     * Only string headers (type 7) occur in Bedrock streams; anything else ends the header block.
     *
     * @return array<string, string>
     */
    private static function eventStreamHeaders(string $block): array
    {
        $headers = [];
        for ($i = 0; $i < strlen($block);) {
            $nameLength = ord($block[$i]);
            $name = substr($block, $i + 1, $nameLength);
            $i += 1 + $nameLength;
            if (ord($block[$i] ?? "\0") !== 7) {
                break;
            }
            $valueLength = Payload::int((unpack('n', $block, $i + 1) ?: [])[1] ?? null);
            $headers[$name] = substr($block, $i + 3, $valueLength);
            $i += 3 + $valueLength;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $chunk
     */
    private static function eventStreamFrame(array $chunk): string
    {
        $headers = '';
        foreach ([':event-type' => Payload::string($chunk['_event'] ?? null), ':content-type' => 'application/json', ':message-type' => 'event'] as $name => $value) {
            $headers .= chr(strlen($name)) . $name . chr(7) . pack('n', strlen($value)) . $value;
        }
        unset($chunk['_event']);
        $payload = (string) json_encode($chunk === [] ? new \stdClass() : $chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $prelude = pack('NN', 16 + strlen($headers) + strlen($payload), strlen($headers));
        $message = $prelude . pack('N', crc32($prelude)) . $headers . $payload;

        return $message . pack('N', crc32($message));
    }

    private static function sseData(string $event): string
    {
        $data = [];
        foreach (explode("\n", $event) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5), ' ');
            }
        }

        return implode("\n", $data);
    }

    /**
     * @param list<array<string, mixed>> $chunks
     * @return array<string, mixed>
     */
    private function mergeOpenAi(array $chunks): array
    {
        $model = '';
        $content = '';
        $usage = [];
        /** @var array<int, array{name: string, arguments: string}> $calls */
        $calls = [];
        foreach ($chunks as $chunk) {
            $model = Payload::string($chunk['model'] ?? null) ?: $model;
            $usage = isset($chunk['usage']) ? Payload::map($chunk['usage']) : $usage;
            $delta = Payload::get($chunk, 'choices', 0, 'delta');
            $content .= Payload::string(Payload::get($delta, 'content'));
            foreach (Payload::maps(Payload::get($delta, 'tool_calls')) as $position => $call) {
                $index = isset($call['index']) ? Payload::int($call['index']) : $position;
                $calls[$index] ??= ['name' => '', 'arguments' => ''];
                $calls[$index]['name'] .= Payload::string(Payload::get($call, 'function', 'name'));
                $calls[$index]['arguments'] .= Payload::string(Payload::get($call, 'function', 'arguments'));
            }
        }
        ksort($calls);

        return [
            'model' => $model,
            'choices' => [['message' => [
                'content' => $content,
                'tool_calls' => array_values(array_map(static fn(array $c): array => ['function' => $c], $calls)),
            ]]],
            'usage' => $usage,
        ];
    }

    /**
     * The terminal `response.completed` (or `.incomplete` / `.failed`) event carries the full response object.
     *
     * @param list<array<string, mixed>> $chunks
     * @return array<string, mixed>
     */
    private function mergeOpenAiResponses(array $chunks): array
    {
        $response = [];
        foreach ($chunks as $chunk) {
            $response = isset($chunk['response']) ? Payload::map($chunk['response']) : $response;
        }

        return $response;
    }

    /**
     * @param list<array<string, mixed>> $chunks
     * @return array<string, mixed>
     */
    private function mergeAnthropic(array $chunks): array
    {
        $model = '';
        $usage = [];
        /** @var array<int, array{type: string, name: string, text: string, json: string}> $blocks */
        $blocks = [];
        foreach ($chunks as $chunk) {
            $index = Payload::int($chunk['index'] ?? null);
            switch ($chunk['type'] ?? null) {
                case 'message_start':
                    $model = Payload::string(Payload::get($chunk, 'message', 'model'));
                    $usage = Payload::map(Payload::get($chunk, 'message', 'usage'));
                    break;
                case 'content_block_start':
                    $blocks[$index] = [
                        'type' => Payload::string(Payload::get($chunk, 'content_block', 'type')),
                        'name' => Payload::string(Payload::get($chunk, 'content_block', 'name')),
                        'text' => '',
                        'json' => '',
                    ];
                    break;
                case 'content_block_delta':
                    $blocks[$index] ??= ['type' => 'text', 'name' => '', 'text' => '', 'json' => ''];
                    $blocks[$index]['text'] .= Payload::string(Payload::get($chunk, 'delta', 'text'));
                    $blocks[$index]['json'] .= Payload::string(Payload::get($chunk, 'delta', 'partial_json'));
                    break;
                case 'message_delta':
                    // Cumulative counts; zero/null fields mean "not reported here", not "reset".
                    $usage = array_merge($usage, array_filter(Payload::map($chunk['usage'] ?? null)));
                    break;
            }
        }
        ksort($blocks);

        $content = [];
        foreach ($blocks as $block) {
            if ($block['type'] === 'text') {
                $content[] = ['type' => 'text', 'text' => $block['text']];
            } elseif ($block['type'] === 'tool_use') {
                $content[] = ['type' => 'tool_use', 'name' => $block['name'], 'input' => Payload::arguments($block['json'])];
            }
        }

        return ['model' => $model, 'content' => $content, 'usage' => $usage];
    }

    /**
     * @param list<array<string, mixed>> $chunks
     * @return array<string, mixed>
     */
    private function mergeGemini(array $chunks): array
    {
        $parts = [];
        $usage = [];
        $model = '';
        foreach ($chunks as $chunk) {
            array_push($parts, ...Payload::maps(Payload::get($chunk, 'candidates', 0, 'content', 'parts')));
            $usage = isset($chunk['usageMetadata']) ? Payload::map($chunk['usageMetadata']) : $usage;
            $model = Payload::string($chunk['modelVersion'] ?? null) ?: $model;
        }

        return [
            'modelVersion' => $model,
            'candidates' => [['content' => ['parts' => $parts]]],
            'usageMetadata' => $usage,
        ];
    }

    /**
     * @param list<array<string, mixed>> $chunks
     * @return array<string, mixed>
     */
    private function mergeBedrock(array $chunks): array
    {
        /** @var array<int, array{text: string, name: string, input: string}> $blocks */
        $blocks = [];
        $usage = [];
        foreach ($chunks as $chunk) {
            $index = Payload::int($chunk['contentBlockIndex'] ?? null);
            match ($chunk['_event'] ?? null) {
                'contentBlockStart' => $blocks[$index] = ['text' => '', 'name' => Payload::string(Payload::get($chunk, 'start', 'toolUse', 'name')), 'input' => ''],
                'contentBlockDelta' => $blocks[$index] = [
                    'text' => ($blocks[$index]['text'] ?? '') . Payload::string(Payload::get($chunk, 'delta', 'text')),
                    'name' => $blocks[$index]['name'] ?? '',
                    'input' => ($blocks[$index]['input'] ?? '') . Payload::string(Payload::get($chunk, 'delta', 'toolUse', 'input')),
                ],
                'metadata' => $usage = Payload::map($chunk['usage'] ?? null),
                default => null,
            };
        }
        ksort($blocks);

        $content = [];
        foreach ($blocks as $block) {
            $content[] = $block['name'] !== ''
                ? ['toolUse' => ['name' => $block['name'], 'input' => Payload::arguments($block['input'])]]
                : ['text' => $block['text']];
        }

        return ['output' => ['message' => ['content' => $content]], 'usage' => $usage];
    }

    /**
     * The final `done: true` chunk holds the counts; text and tool calls are spread over the earlier ones.
     *
     * @param list<array<string, mixed>> $chunks
     * @return array<string, mixed>
     */
    private function mergeOllama(array $chunks): array
    {
        if ($chunks === []) {
            return [];
        }
        $merged = $chunks[array_key_last($chunks)];
        $content = '';
        $generated = '';
        $calls = [];
        foreach ($chunks as $chunk) {
            $content .= Payload::string(Payload::get($chunk, 'message', 'content'));
            $generated .= Payload::string($chunk['response'] ?? null);
            array_push($calls, ...Payload::maps(Payload::get($chunk, 'message', 'tool_calls')));
        }
        if (isset($merged['message'])) {
            $merged['message'] = ['content' => $content, 'tool_calls' => $calls];
        }
        if (isset($merged['response'])) {
            $merged['response'] = $generated;
        }

        return $merged;
    }
}
