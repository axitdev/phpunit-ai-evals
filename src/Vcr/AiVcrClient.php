<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Vcr;

use Axitdev\PhpUnitAiEvals\Judge\JudgeVerdict;
use Axitdev\PhpUnitAiEvals\PhpUnit\TestContextRegistry;
use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use Axitdev\PhpUnitAiEvals\Trace\Parser\AnthropicPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\BedrockConversePayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\GeminiPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\OllamaPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\OpenAiPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\OpenAiResponsesPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\ProviderPayloadParserInterface;
use Axitdev\PhpUnitAiEvals\Trace\Parser\StreamingPayloadParserInterface;
use Axitdev\PhpUnitAiEvals\Trace\ToolCall;
use Axitdev\PhpUnitAiEvals\Vcr\Exception\CassetteMissException;
use DateTimeImmutable;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 decorator that records and replays LLM calls, Judge verdicts and raw HTTP traffic.
 */
final class AiVcrClient implements ClientInterface
{
    private const DROPPED_RESPONSE_HEADERS = ['content-length', 'content-encoding', 'transfer-encoding'];

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private const PROMPT_KEYS = ['messages', 'contents', 'input', 'prompt'];

    /** Rate limits, timeouts and rejected credentials say nothing about the request. */
    private const UNRECORDED_STATUSES = [401, 403, 407, 408, 429];

    private ?Cassette $cassette = null;

    /** @var list<ProviderPayloadParserInterface> */
    private readonly array $parsers;

    private readonly PromptNormalizer $normalizer;

    private readonly StreamRebuilder $streams;

    private readonly ResponseFactoryInterface $responseFactory;

    private readonly StreamFactoryInterface $streamFactory;

    /** @var array{conversation: string, index: int}|null the conversation turn requests are scoped to, if any */
    private ?array $turn = null;

    /** @var array<string, int> per prompt list: how many entries came before the current turn */
    private array $turnBase = [];

    /** @var list<CassetteMissException> */
    private array $misses = [];

    /**
     * @internal Tests get one from AiAssertionsTrait::wrapHttpClient()
     *
     * @param list<ProviderPayloadParserInterface>|null $parsers
     */
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly CassetteStorage $storage,
        private readonly string $cassettePath,
        private readonly string $testName,
        private readonly VcrMode $mode,
        private readonly AiTraceCollector $collector,
        ?PromptNormalizer $normalizer = null,
        ?array $parsers = null,
        private readonly SecretScrubber $scrubber = new SecretScrubber(),
        private readonly OccurrenceCounter $occurrences = new OccurrenceCounter(),
    ) {
        $this->normalizer = $normalizer ?? new PromptNormalizer();
        $this->parsers = $parsers ?? self::defaultParsers();
        $this->streams = new StreamRebuilder();
        $this->responseFactory = Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * The built-in parsers, in matching order; the first whose supports() accepts the URI wins.
     *
     * @return list<ProviderPayloadParserInterface>
     */
    public static function defaultParsers(): array
    {
        return [
            new OpenAiPayloadParser(),
            new OpenAiResponsesPayloadParser(),
            new AnthropicPayloadParser(),
            new GeminiPayloadParser(),
            new OllamaPayloadParser(),
            new BedrockConversePayloadParser(),
        ];
    }

    public function inner(): ClientInterface
    {
        return $this->inner;
    }

    public function mode(): VcrMode
    {
        return $this->mode;
    }

    /**
     * Misses raised by this client, including any the code under test caught and swallowed.
     *
     * @return list<CassetteMissException>
     */
    public function misses(): array
    {
        return $this->misses;
    }

    /**
     * Scopes the following LLM requests to one turn of a scripted conversation: they match by the turn's position,
     * its own messages and the unchanged parts of the request (system prompt, tools, model), not by the replies of
     * earlier turns. A changed turn then re-records only itself.
     *
     * @internal Used by AiAssertionsTrait::runConversation()
     */
    public function enterTurn(string $conversation, int $index): void
    {
        $this->turn = ['conversation' => $conversation, 'index' => $index];
        $this->turnBase = [];
    }

    /**
     * @internal
     */
    public function leaveTurn(): void
    {
        $this->turn = null;
        $this->turnBase = [];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $stream = $request->getBody();
        $body = (string) $stream;
        if ($stream->isSeekable()) {
            $stream->rewind();
        } else {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }
        $uri = (string) $request->getUri();

        [$kind, $parser, $payload] = $this->classify($request, $uri, $body);
        $matched = $this->matchPayload($kind, $payload, $request);
        $hash = $this->normalizer->hash($matched);
        $occurrence = $this->occurrences->next($kind, $hash);

        if ($this->mode->replays()) {
            $entry = $this->cassette()->find($kind, $hash, $occurrence);
            if ($entry !== null) {
                $this->markUsed($kind, $hash, $occurrence);

                return $this->replay($entry, $parser, $payload);
            }
            if ($this->mode === VcrMode::Replay) {
                throw $this->miss($kind, $hash, $occurrence, $matched);
            }
        }

        $start = hrtime(true);
        try {
            $live = $this->inner->sendRequest($request);
            // Timed after the body is read: streaming clients return before it has arrived.
            $raw = (string) $live->getBody();
        } catch (\Throwable $e) {
            $this->occurrences->release($kind, $hash);

            throw $e;
        }
        $latencyMs = (hrtime(true) - $start) / 1e6;
        $status = $live->getStatusCode();

        $isStream = $parser !== null && StreamRebuilder::isStream($live->getHeaderLine('Content-Type'));
        $chunks = $isStream ? $this->streams->parse($raw, $live->getHeaderLine('Content-Type')) : [];
        $decoded = $isStream ? null : json_decode($raw, true);

        $recordable = $status < 500 && !in_array($status, self::UNRECORDED_STATUSES, true) && !StreamRebuilder::hasError($chunks);
        if (!$recordable) {
            $this->occurrences->release($kind, $hash);
        }

        $interaction = $this->trace(
            $parser,
            $payload,
            $isStream ? $this->mergeStream($chunks, $parser) : $this->parseJsonBody($decoded, $parser),
            $latencyMs,
            $status,
            false,
        );

        if ($this->mode->records() && $recordable) {
            $bodyField = $isStream ? $chunks : (is_array($decoded) && self::encodesBack($decoded, $raw) ? $decoded : $raw);
            $binary = is_string($bodyField) && !mb_check_encoding($bodyField, 'UTF-8');
            if ($kind === Cassette::KIND_RAW && !$binary) {
                $bodyField = is_array($bodyField) ? $this->scrubber->scrubData($bodyField) : $this->scrubber->scrubBody($bodyField);
            }
            if (!$binary) {
                $bodyField = $this->scrubber->scrubValues($bodyField);
            }
            $entry = [
                'semantic_hash' => $hash,
                'occurrence_index' => $occurrence,
                'kind' => $kind,
                'provider' => $parser?->name(),
                'model' => Payload::string($payload['model'] ?? null),
                'recorded_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'request' => [
                    'uri' => $this->scrubber->scrubValues($this->scrubber->scrubUri($uri)),
                    'headers' => $this->scrubber->scrubHeaders($request->getHeaders()),
                    'normalized_payload' => $matched,
                ],
                'response' => [
                    'status' => $status,
                    'headers' => $this->scrubber->scrubHeaders(array_filter(
                        $live->getHeaders(),
                        static fn(string $name): bool => !in_array(strtolower($name), self::DROPPED_RESPONSE_HEADERS, true),
                        ARRAY_FILTER_USE_KEY,
                    )),
                    'is_stream' => $isStream,
                ] + ($binary ? ['body_base64' => base64_encode($bodyField)] : ['body' => $bodyField]),
            ];
            if ($interaction !== null) {
                $entry['trace'] = $this->scrubber->scrubValues($this->traceToArray($interaction));
            }
            if ($this->turn !== null && $kind === Cassette::KIND_AGENT) {
                $entry['turn'] = $this->turn;
            }
            $this->store($entry);
        }

        return $live->withBody($this->streamFactory->createStream($raw));
    }

    /**
     * @param array<string, mixed> $payload Messages/model sent to the judge; hashed like any other prompt
     * @param callable(): JudgeVerdict $live
     */
    public function judgeVerdict(array $payload, callable $live): JudgeVerdict
    {
        $normalized = $this->normalizer->match($this->scrubber->scrubMap($this->normalizer->normalize($payload)));
        $hash = $this->normalizer->hash($normalized);
        $occurrence = $this->occurrences->next(Cassette::KIND_JUDGE, $hash);

        if ($this->mode->replays()) {
            $entry = $this->cassette()->find(Cassette::KIND_JUDGE, $hash, $occurrence);
            if ($entry !== null) {
                $this->markUsed(Cassette::KIND_JUDGE, $hash, $occurrence);
                $verdict = Payload::map($entry['verdict'] ?? null);

                return new JudgeVerdict(Payload::float($verdict['score'] ?? null), Payload::string($verdict['reason'] ?? null));
            }
            if ($this->mode === VcrMode::Replay) {
                throw $this->miss(Cassette::KIND_JUDGE, $hash, $occurrence, $normalized);
            }
        }

        try {
            $verdict = $live();
        } catch (\Throwable $e) {
            $this->occurrences->release(Cassette::KIND_JUDGE, $hash);

            throw $e;
        }
        if ($this->mode->records()) {
            $this->store([
                'semantic_hash' => $hash,
                'occurrence_index' => $occurrence,
                'kind' => Cassette::KIND_JUDGE,
                'model' => Payload::string($payload['model'] ?? null),
                'recorded_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'request' => ['normalized_payload' => $normalized],
                'verdict' => ['score' => $verdict->score, 'reason' => $verdict->reason],
            ]);
        }

        return $verdict;
    }

    /**
     * The request as the provider parser (or, for other traffic, RequestBody) sees it, normalized and with secrets
     * redacted. MatchingRules aren't applied yet: the trace still needs fields such as `model`.
     *
     * @return array{string, ProviderPayloadParserInterface|null, array<string, mixed>}
     */
    private function classify(RequestInterface $request, string $uri, string $body): array
    {
        foreach ($this->parsers as $parser) {
            if (!$parser->supports($uri)) {
                continue;
            }
            $decoded = json_decode($body, true);
            $payload = is_array($decoded) ? $this->normalizer->normalize($parser->requestPayload($uri, $decoded)) : [];
            // Every provider request names a model (in the body or URL) and carries a prompt; anything else is an app
            // endpoint like /api/chat or /survey/responses, and hashing only the LLM fields would merge distinct requests.
            if (!isset($payload['model']) || array_intersect_key($payload, array_flip(self::PROMPT_KEYS)) === []) {
                break;
            }

            return [Cassette::KIND_AGENT, $parser, $this->scrubber->scrubMap($payload)];
        }

        $described = RequestBody::describe($body, $request->getHeaderLine('Content-Type'));
        $described = is_string($described) ? $this->scrubber->scrubBody($described) : $this->scrubber->scrubData($described);

        return [
            Cassette::KIND_RAW,
            null,
            $this->scrubber->scrubMap(
                $this->normalizer->normalizeRaw($request->getMethod(), $this->scrubber->scrubUri($uri), $described),
            ),
        ];
    }

    /**
     * What is hashed, and stored as `normalized_payload`: the payload after MatchingRules, plus the matched headers,
     * scoped to the current conversation turn if there is one.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function matchPayload(string $kind, array $payload, RequestInterface $request): array
    {
        $rules = $this->normalizer->rules();
        if ($rules->matchHeaders !== []) {
            $headers = $rules->pickHeaders($this->scrubber->scrubHeaders($request->getHeaders()));
            if ($headers !== []) {
                $payload['headers'] = $this->scrubber->scrubValues($headers);
            }
        }
        $payload = $this->normalizer->match($payload);

        return $this->turn !== null && $kind === Cassette::KIND_AGENT ? $this->scopeToTurn($payload) : $payload;
    }

    /**
     * Cuts the messages that came before this turn from each prompt list, so earlier replies don't change the hash.
     * Leading system and developer messages always stay: editing the system prompt must still re-record. Of the rest,
     * the first request of the turn fixes the cut (everything but its last entry, the new user message); requests
     * later in the same turn, such as tool-call rounds, keep what was added since. Lists are told apart by their
     * first entry, so a side call with its own prompt (a classifier, a router) gets its own cut.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function scopeToTurn(array $payload): array
    {
        assert($this->turn !== null);
        foreach (self::PROMPT_KEYS as $key) {
            $list = $payload[$key] ?? null;
            if (!is_array($list) || $list === [] || !array_is_list($list)) {
                continue;
            }
            $lead = 0;
            while ($lead < count($list) && in_array(Payload::get($list[$lead], 'role'), ['system', 'developer'], true)) {
                $lead++;
            }
            $rest = array_slice($list, $lead);
            if ($rest === []) {
                continue;
            }
            $id = $key . ':' . hash('xxh128', (string) json_encode($list[0]));
            $base = $this->turnBase[$id] ??= count($rest) - 1;
            $payload[$key] = [...array_slice($list, 0, $lead), ...array_slice($rest, min($base, count($rest) - 1))];
        }
        // OpenAI Responses chains turns server-side; the id changes whenever an earlier turn is re-recorded.
        unset($payload['previous_response_id']);

        return ['conversation_turn' => $this->turn['conversation'] . '#' . $this->turn['index']] + $payload;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $payload
     */
    private function replay(array $entry, ?ProviderPayloadParserInterface $parser, array $payload): ResponseInterface
    {
        $response = Payload::map($entry['response'] ?? null);
        $stored = $response['body'] ?? null;
        $isStream = ($response['is_stream'] ?? false) === true;

        if ($isStream) {
            $chunks = Payload::maps($stored);
            $raw = $parser instanceof StreamingPayloadParserInterface
                ? $parser->renderStream($chunks)
                : $this->streams->render($chunks, $parser?->name() ?? '');
            $parsed = $this->mergeStream($chunks, $parser);
        } elseif (is_string($response['body_base64'] ?? null)) {
            $raw = (string) base64_decode($response['body_base64'], true);
            $parsed = [];
        } else {
            $raw = is_string($stored) ? $stored : (string) json_encode($stored, self::JSON_FLAGS);
            $parsed = $this->parseJsonBody(is_string($stored) ? json_decode($stored, true) : $stored, $parser);
        }

        $status = Payload::int($response['status'] ?? null) ?: 200;
        $this->trace($parser, $payload, $parsed, Payload::float(Payload::get($entry, 'trace', 'latency_ms')), $status, true);

        $replayed = $this->responseFactory->createResponse($status)->withBody($this->streamFactory->createStream($raw));
        foreach (Payload::map($response['headers'] ?? null) as $name => $values) {
            $replayed = $replayed->withHeader($name, array_map(Payload::string(...), is_array($values) ? $values : [$values]));
        }

        return $replayed;
    }

    /**
     * PHP decodes `{}` and `[]` to the same empty array, so a body is stored decoded (readable) only when encoding it
     * again gives the same JSON; otherwise the exact text is kept, e.g. OpenAI's `"metadata": {}`.
     *
     * @param array<mixed> $decoded
     */
    private static function encodesBack(array $decoded, string $raw): bool
    {
        return json_encode($decoded, self::JSON_FLAGS) === json_encode(json_decode($raw), self::JSON_FLAGS);
    }

    /**
     * Gemini's streamGenerateContent without `?alt=sse` answers with a JSON array of chunks instead of SSE.
     *
     * @return array<string, mixed>
     */
    private function parseJsonBody(mixed $decoded, ?ProviderPayloadParserInterface $parser): array
    {
        return is_array($decoded) && $decoded !== [] && array_is_list($decoded)
            ? $this->mergeStream(Payload::maps($decoded), $parser)
            : Payload::map($decoded);
    }

    /**
     * @param list<array<string, mixed>> $chunks
     * @return array<string, mixed>
     */
    private function mergeStream(array $chunks, ?ProviderPayloadParserInterface $parser): array
    {
        return $parser instanceof StreamingPayloadParserInterface
            ? $parser->mergeStream($chunks)
            : $this->streams->merge($chunks, $parser?->name() ?? '');
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $response
     */
    private function trace(
        ?ProviderPayloadParserInterface $parser,
        array $request,
        array $response,
        float $latencyMs,
        int $status,
        bool $replayed,
    ): ?AiInteraction {
        if ($parser === null || $status < 200 || $status >= 300 || $response === []) {
            return null;
        }
        $interaction = $parser->parse($request, $response, $latencyMs);
        $this->collector->record($interaction, $replayed);

        return $interaction;
    }

    /**
     * @return array<string, mixed>
     */
    private function traceToArray(AiInteraction $i): array
    {
        return [
            'prompt_tokens' => $i->promptTokens,
            'completion_tokens' => $i->completionTokens,
            'reasoning_tokens' => $i->reasoningTokens,
            'latency_ms' => round($i->latencyMs, 1),
            'tool_calls' => array_map(static fn(ToolCall $c): array => ['name' => $c->name, 'arguments' => $c->arguments], $i->toolCalls),
        ];
    }

    private function cassette(): Cassette
    {
        return $this->cassette ??= $this->storage->load($this->cassettePath) ?? new Cassette($this->testName);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function store(array $entry): void
    {
        $this->storage->merge($this->cassettePath, new Cassette($this->testName, [$entry]));
        $this->markUsed(Payload::string($entry['kind']), Payload::string($entry['semantic_hash']), Payload::int($entry['occurrence_index']));
    }

    private function markUsed(string $kind, string $hash, int $occurrence): void
    {
        TestContextRegistry::markUsed($this->cassettePath, Cassette::key($kind, $hash, $occurrence));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function miss(string $kind, string $hash, int $occurrence, array $payload): CassetteMissException
    {
        $cassette = $this->cassette();

        return $this->misses[] = CassetteMissException::create(
            $this->cassettePath,
            $this->testName,
            $kind,
            $hash,
            $occurrence,
            $payload,
            $cassette->closestPayload($kind, $payload),
            $cassette->hasHash($kind, $hash),
        );
    }
}
