<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Live;

use Axitdev\PhpUnitAiEvals\Judge\JudgePrompts;
use Axitdev\PhpUnitAiEvals\Judge\JudgeSettings;
use Axitdev\PhpUnitAiEvals\Judge\Psr18JudgeClient;
use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Tests\Support\FakeClient;
use Axitdev\PhpUnitAiEvals\Tests\Support\TempDirTrait;
use Axitdev\PhpUnitAiEvals\Trace\AiInteraction;
use Axitdev\PhpUnitAiEvals\Trace\AiTraceCollector;
use Axitdev\PhpUnitAiEvals\Vcr\AiVcrClient;
use Axitdev\PhpUnitAiEvals\Vcr\CassetteStorage;
use Axitdev\PhpUnitAiEvals\Vcr\VcrMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Calls real provider APIs, records the traffic, then replays it offline and compares. Not part of the default
 * suites; run with `vendor/bin/phpunit tests/Live` and the provider keys in the environment.
 */
final class LiveProvidersTest extends TestCase
{
    use TempDirTrait;

    private const WEATHER_PROMPT = 'What is the weather in Paris right now? Use the tool.';

    /**
     * @return iterable<string, array{string, string, bool, bool}> provider, scenario, streaming, expects tool call
     */
    public static function scenarios(): iterable
    {
        foreach (['anthropic', 'gemini', 'openai', 'openai-responses'] as $provider) {
            yield "$provider text" => [$provider, 'text', false, false];
            yield "$provider tool call" => [$provider, 'tool', false, true];
            yield "$provider streaming text" => [$provider, 'text', true, false];
            yield "$provider streaming tool call" => [$provider, 'tool', true, true];
        }
    }

    #[DataProvider('scenarios')]
    public function testRecordsTracesAndReplaysThemOffline(string $provider, string $scenario, bool $stream, bool $expectsTool): void
    {
        $key = self::key($provider);
        $request = self::request($provider, $key, $scenario === 'tool' ? self::WEATHER_PROMPT : 'Say hello in exactly three words.', $scenario === 'tool', $stream);

        [$liveBody, $live] = $this->send(new Psr18Client(), VcrMode::Record, $request);

        self::assertSame($provider, $live->provider);
        self::assertGreaterThan(0, $live->promptTokens, 'prompt tokens parsed');
        self::assertGreaterThan(0, $live->completionTokens, 'completion tokens parsed');
        self::assertNotSame('', $live->model, 'model parsed');
        if ($expectsTool) {
            self::assertSame('get_weather', $live->toolCalls[0]->name ?? null, 'tool call parsed');
            self::assertStringContainsStringIgnoringCase('paris', (string) json_encode($live->toolCalls[0]->arguments));
        } else {
            self::assertNotSame('', trim($live->assistantContent), 'content parsed');
        }

        $cassette = (string) file_get_contents($this->tmp . '/live.json');
        self::assertStringNotContainsString($key, $cassette, 'API key must not reach the cassette');

        [$replayBody, $replayed] = $this->send(new FakeClient(), VcrMode::Replay, $request);

        // The cassette stores latency rounded to 0.1 ms; everything else must match exactly.
        self::assertEqualsWithDelta($live->latencyMs, $replayed->latencyMs, 0.05);
        self::assertEquals(
            [$live->provider, $live->model, $live->promptTokens, $live->completionTokens, $live->reasoningTokens, $live->assistantContent, $live->toolCalls],
            [$replayed->provider, $replayed->model, $replayed->promptTokens, $replayed->completionTokens, $replayed->reasoningTokens, $replayed->assistantContent, $replayed->toolCalls],
            'replay rebuilds the same trace',
        );
        if (!$stream) {
            self::assertJsonStringEqualsJsonString($liveBody, $replayBody);
        }
    }

    /**
     * @return iterable<string, array{string, string, string, bool}> provider, base URI, model, JSON mode
     */
    public static function judges(): iterable
    {
        // Anthropic's compatibility layer rejects `response_format: json_object`, so JSON mode is off there.
        yield 'claude' => ['anthropic', 'https://api.anthropic.com/v1', 'claude-haiku-5-5', false];
        yield 'gemini' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/openai', 'gemini-3.8-flash', true];
        yield 'openai' => ['openai', 'https://api.openai.com/v1', 'gpt-6-luna', true];
    }

    #[DataProvider('judges')]
    public function testOpenAiCompatibleJudgeEndpoints(string $provider, string $baseUri, string $model, bool $jsonMode): void
    {
        $judge = new Psr18JudgeClient(new Psr18Client(), new JudgeSettings($baseUri, $model, self::key($provider), jsonMode: $jsonMode));

        $good = $judge->judge(JudgePrompts::RUBRIC_SYSTEM, JudgePrompts::rubric('Confirms the refund politely', 'Thank you for your patience, your refund has been issued.'));
        $bad = $judge->judge(JudgePrompts::RUBRIC_SYSTEM, JudgePrompts::rubric('Confirms the refund politely', 'No.'));

        // Judges differ in strictness, so only the ordering is checked, not an absolute score.
        self::assertGreaterThan($bad->score, $good->score, "good: {$good->reason}\nbad: {$bad->reason}");
        self::assertNotSame('', $good->reason);
    }

    public function testAnthropicPromptCacheTokensCountAsPrompt(): void
    {
        $key = self::key('anthropic');
        $system = [['type' => 'text', 'text' => str_repeat('You are a meticulous assistant. Answer briefly. ', 600), 'cache_control' => ['type' => 'ephemeral']]];
        $request = static fn(string $q): RequestInterface => self::anthropic($key, ['system' => $system, 'messages' => [['role' => 'user', 'content' => $q]]]);
        $client = new Psr18Client();

        $this->send($client, VcrMode::Live, $request('Say one.'));
        [$body, $second] = $this->send($client, VcrMode::Live, $request('Say two.'));

        $usage = Payload::map(Payload::get(json_decode($body, true), 'usage'));
        $cached = Payload::int($usage['cache_read_input_tokens'] ?? null) + Payload::int($usage['cache_creation_input_tokens'] ?? null);
        self::assertGreaterThan(0, $cached, 'the long system prompt was cached: ' . json_encode($usage));
        self::assertSame(Payload::int($usage['input_tokens'] ?? null) + $cached, $second->promptTokens);
    }

    /**
     * @return array{string, AiInteraction}
     */
    private function send(ClientInterface $inner, VcrMode $mode, RequestInterface $request): array
    {
        $storage = new CassetteStorage($this->tmp);
        $collector = new AiTraceCollector();
        $vcr = new AiVcrClient($inner, $storage, $storage->pathFor('live'), 'live', $mode, $collector);

        $body = (string) $vcr->sendRequest($request)->getBody();

        return [$body, $collector->last() ?? self::fail("No trace. Response body:\n" . substr($body, 0, 2000))];
    }

    private static function key(string $provider): string
    {
        $var = ['anthropic' => 'ANTHROPIC_API_KEY', 'gemini' => 'GEMINI_API_KEY', 'openai' => 'OPENAI_API_KEY', 'openai-responses' => 'OPENAI_API_KEY'][$provider];
        $key = getenv($var);
        if (!is_string($key) || $key === '') {
            self::markTestSkipped("$var is not set.");
        }

        return $key;
    }

    private static function request(string $provider, string $key, string $prompt, bool $withTool, bool $stream): RequestInterface
    {
        $schema = ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']];
        $description = 'Get the current weather for a city.';

        return match ($provider) {
            'anthropic' => self::anthropic($key, [
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'stream' => $stream,
            ] + ($withTool ? ['tools' => [['name' => 'get_weather', 'description' => $description, 'input_schema' => $schema]], 'tool_choice' => ['type' => 'any']] : [])),
            'gemini' => self::json(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:' . ($stream ? 'streamGenerateContent?alt=sse' : 'generateContent'),
                ['x-goog-api-key' => $key],
                ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]]]
                    + ($withTool ? [
                        'tools' => [['functionDeclarations' => [['name' => 'get_weather', 'description' => $description, 'parameters' => $schema]]]],
                        'toolConfig' => ['functionCallingConfig' => ['mode' => 'ANY']],
                    ] : []),
            ),
            'openai' => self::json(
                'https://api.openai.com/v1/chat/completions',
                ['Authorization' => 'Bearer ' . $key],
                ['model' => 'gpt-6-luna', 'messages' => [['role' => 'user', 'content' => $prompt]]]
                    + ($stream ? ['stream' => true, 'stream_options' => ['include_usage' => true]] : [])
                    // gpt-6 models only allow function tools on Chat Completions without reasoning.
                    + ($withTool ? ['tools' => [['type' => 'function', 'function' => ['name' => 'get_weather', 'description' => $description, 'parameters' => $schema]]], 'tool_choice' => 'required', 'reasoning_effort' => 'none'] : []),
            ),
            'openai-responses' => self::json(
                'https://api.openai.com/v1/responses',
                ['Authorization' => 'Bearer ' . $key],
                ['model' => 'gpt-6-luna', 'input' => $prompt, 'stream' => $stream]
                    + ($withTool ? ['tools' => [['type' => 'function', 'name' => 'get_weather', 'description' => $description, 'parameters' => $schema]], 'tool_choice' => 'required'] : []),
            ),
            default => self::fail("Unknown provider $provider"),
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function anthropic(string $key, array $body): RequestInterface
    {
        return self::json(
            'https://api.anthropic.com/v1/messages',
            ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'],
            ['model' => 'claude-haiku-5-5', 'max_tokens' => 200] + $body,
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     */
    private static function json(string $uri, array $headers, array $body): RequestInterface
    {
        $request = (new Psr18Client())->createRequest('POST', $uri)
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new Psr18Client())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }
}
