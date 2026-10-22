<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Support\Payload;
use Axitdev\PhpUnitAiEvals\Trace\Parser\AnthropicPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\BedrockConversePayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\GeminiPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\OllamaPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\OpenAiPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\OpenAiResponsesPayloadParser;
use Axitdev\PhpUnitAiEvals\Trace\Parser\ProviderPayloadParserInterface;
use Axitdev\PhpUnitAiEvals\Trace\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayloadParsersTest extends TestCase
{
    /**
     * @return iterable<string, array{ProviderPayloadParserInterface, string, bool}>
     */
    public static function supportCases(): iterable
    {
        yield 'openai yes' => [new OpenAiPayloadParser(), 'https://api.openai.com/v1/chat/completions', true];
        yield 'openai compat' => [new OpenAiPayloadParser(), 'http://localhost:8000/v1/chat/completions?x=1', true];
        yield 'openai ollama-compat' => [new OpenAiPayloadParser(), 'http://localhost:11434/api/chat', false];
        yield 'responses yes' => [new OpenAiResponsesPayloadParser(), 'https://api.openai.com/v1/responses', true];
        yield 'responses no' => [new OpenAiResponsesPayloadParser(), 'https://api.openai.com/v1/chat/completions', false];
        yield 'anthropic yes' => [new AnthropicPayloadParser(), 'https://api.anthropic.com/v1/messages', true];
        yield 'anthropic no' => [new AnthropicPayloadParser(), 'https://api.openai.com/v1/chat/completions', false];
        yield 'gemini yes' => [new GeminiPayloadParser(), 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent?key=k', true];
        yield 'gemini stream' => [new GeminiPayloadParser(), 'https://x/v1beta/models/g:streamGenerateContent', true];
        yield 'gemini no' => [new GeminiPayloadParser(), 'https://x/v1beta/models/g:embedContent', false];
        yield 'ollama chat' => [new OllamaPayloadParser(), 'http://localhost:11434/api/chat', true];
        yield 'ollama generate' => [new OllamaPayloadParser(), 'http://localhost:11434/api/generate', true];
        yield 'ollama no' => [new OllamaPayloadParser(), 'http://localhost:11434/api/tags', false];
    }

    #[DataProvider('supportCases')]
    public function testSupports(ProviderPayloadParserInterface $parser, string $uri, bool $expected): void
    {
        self::assertSame($expected, $parser->supports($uri));
    }

    public function testProvidersAddUrlDetailsToThePayload(): void
    {
        $gemini = new GeminiPayloadParser();
        self::assertSame(['contents' => [], 'model' => 'gemini-x', 'stream' => true], $gemini->requestPayload('https://g.test/v1beta/models/gemini-x:streamGenerateContent?alt=sse', ['contents' => []]));
        self::assertSame(['model' => 'own'], $gemini->requestPayload('https://g.test/v1beta/models/gemini-x:generateContent', ['model' => 'own']));

        self::assertSame(['model' => 'claude-x'], (new AnthropicPayloadParser())->requestPayload('https://v.test/v1/projects/p/locations/l/publishers/anthropic/models/claude-x:rawPredict', []));
        self::assertSame([], (new AnthropicPayloadParser())->requestPayload('https://api.anthropic.com/v1/messages', []));

        $bedrock = new BedrockConversePayloadParser();
        self::assertSame(['model' => 'anthropic.claude-haiku-5-5-v1:0'], $bedrock->requestPayload('https://b.test/model/anthropic.claude-haiku-5-5-v1%3A0/converse', []));
        self::assertSame(['model' => 'anthropic.claude-haiku-5-5-v1:0', 'stream' => true], $bedrock->requestPayload('https://b.test/model/anthropic.claude-haiku-5-5-v1%3A0/converse-stream', []));

        self::assertSame(['x' => 1], (new OpenAiPayloadParser())->requestPayload('https://api.openai.com/v1/chat/completions', ['x' => 1]));
    }

    public function testOpenAiParsesContentToolCallsAndUsage(): void
    {
        $i = (new OpenAiPayloadParser())->parse(['model' => 'gpt-6-sol'], [
            'model' => 'gpt-6.1-sol',
            'choices' => [['message' => [
                'content' => null,
                'tool_calls' => [
                    ['function' => ['name' => 'issue_refund', 'arguments' => '{"order_id":404}']],
                    ['function' => ['name' => 'notify', 'arguments' => '']],
                ],
            ]]],
            'usage' => ['prompt_tokens' => 140, 'completion_tokens' => 35, 'completion_tokens_details' => ['reasoning_tokens' => 10]],
        ], 420.5);

        self::assertSame('openai', $i->provider);
        self::assertSame('gpt-6.1-sol', $i->model);
        self::assertSame([140, 35, 10, 175], [$i->promptTokens, $i->completionTokens, $i->reasoningTokens, $i->totalTokens]);
        self::assertSame(420.5, $i->latencyMs);
        self::assertSame('', $i->assistantContent);
        self::assertEquals([new ToolCall('issue_refund', ['order_id' => 404]), new ToolCall('notify')], $i->toolCalls);
    }

    public function testOpenAiToleratesEmptyResponseAndFallsBackToRequestModel(): void
    {
        $i = (new OpenAiPayloadParser())->parse(['model' => 'req-model'], [], 0.0);

        self::assertSame('req-model', $i->model);
        self::assertSame(0, $i->totalTokens);
        self::assertSame([], $i->toolCalls);
    }

    public function testAnthropicParsesTextAndToolUseBlocks(): void
    {
        $i = (new AnthropicPayloadParser())->parse([], [
            'model' => 'claude-sonnet-5-5',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'x', 'signature' => 's'],
                ['type' => 'text', 'text' => 'Refunding '],
                ['type' => 'text', 'text' => 'now.'],
                ['type' => 'tool_use', 'id' => 't', 'name' => 'issue_refund', 'input' => ['order_id' => 404]],
            ],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 8],
        ], 1.0);

        self::assertSame('Refunding now.', $i->assistantContent);
        self::assertSame([20, 8, 0], [$i->promptTokens, $i->completionTokens, $i->reasoningTokens]);
        self::assertEquals([new ToolCall('issue_refund', ['order_id' => 404])], $i->toolCalls);
        self::assertSame('claude-sonnet-5-5', $i->model);
    }

    public function testAnthropicCountsPromptCacheTokensAsPrompt(): void
    {
        $i = (new AnthropicPayloadParser())->parse([], [
            'usage' => ['input_tokens' => 5, 'cache_read_input_tokens' => 1000, 'cache_creation_input_tokens' => 200, 'output_tokens' => 9],
        ], 0.0);

        self::assertSame([1205, 9], [$i->promptTokens, $i->completionTokens]);
    }

    public function testOpenAiResponsesFallsBackToRequestModelAndIgnoresNonTextParts(): void
    {
        $i = (new OpenAiResponsesPayloadParser())->parse(['model' => 'req'], [
            'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'no'], ['type' => 'output_text', 'text' => 'ok']]]],
        ], 0.0);

        self::assertSame(['req', 'ok', 0], [$i->model, $i->assistantContent, $i->totalTokens]);
    }

    public function testGeminiParsesPartsAndFoldsThoughtTokensIntoCompletion(): void
    {
        $i = (new GeminiPayloadParser())->parse(['model' => 'req'], [
            'modelVersion' => 'gemini-3.8-flash',
            'candidates' => [['content' => ['parts' => [
                ['text' => 'private thoughts', 'thought' => true],
                ['text' => 'Done.'],
                ['functionCall' => ['name' => 'issue_refund', 'args' => ['order_id' => 404]]],
            ]]]],
            'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 10, 'thoughtsTokenCount' => 30],
        ], 2.0);

        self::assertSame('Done.', $i->assistantContent);
        self::assertSame('gemini-3.8-flash', $i->model);
        self::assertSame([50, 40, 30, 90], [$i->promptTokens, $i->completionTokens, $i->reasoningTokens, $i->totalTokens]);
        self::assertEquals([new ToolCall('issue_refund', ['order_id' => 404])], $i->toolCalls);
    }

    public function testOllamaChatShape(): void
    {
        $i = (new OllamaPayloadParser())->parse([], [
            'model' => 'gemma4:e4b',
            'message' => [
                'content' => 'hello',
                'tool_calls' => [['function' => ['name' => 'issue_refund', 'arguments' => ['order_id' => 404]]]],
            ],
            'prompt_eval_count' => 12,
            'eval_count' => 7,
        ], 3.0);

        self::assertSame('hello', $i->assistantContent);
        self::assertSame([12, 7, 0], [$i->promptTokens, $i->completionTokens, $i->reasoningTokens]);
        self::assertEquals([new ToolCall('issue_refund', ['order_id' => 404])], $i->toolCalls);
    }

    public function testOllamaGenerateShape(): void
    {
        $i = (new OllamaPayloadParser())->parse(['model' => 'm'], ['response' => 'plain'], 0.0);

        self::assertSame('plain', $i->assistantContent);
        self::assertSame('m', $i->model);
    }

    public function testPayloadHelpers(): void
    {
        self::assertSame(1, Payload::get(['a' => [1]], 'a', 0));
        self::assertNull(Payload::get(['a' => 1], 'a', 'b'));
        self::assertNull(Payload::get('x', 'a'));
        self::assertSame('', Payload::string(5));
        self::assertSame(5, Payload::int('5'));
        self::assertSame(3, Payload::int(3.9));
        self::assertSame(0, Payload::int('abc'));
        self::assertSame(['0' => 'a'], Payload::map(['a']));
        self::assertSame([], Payload::map('x'));
        self::assertSame([['a' => 1]], Payload::maps([['a' => 1], 'junk', 5]));
        self::assertSame(['k' => 1], Payload::arguments('{"k":1}'));
        self::assertSame([], Payload::arguments('garbage'));
        self::assertSame(['k' => 1], Payload::arguments(['k' => 1]));
    }

    public function testEndpointsMustMatchTheWholePathEnding(): void
    {
        self::assertFalse((new GeminiPayloadParser())->supports('https://g.test/v1beta/models/g:generateContentX'));
        self::assertFalse((new OllamaPayloadParser())->supports('http://localhost:11434/api/chatty'));
        self::assertFalse((new BedrockConversePayloadParser())->supports('https://b.test/model/m/converse/extra'));
        self::assertFalse((new AnthropicPayloadParser())->supports('https://v.test/publishers/anthropic/models/c:rawPredictX'));
        self::assertTrue((new AnthropicPayloadParser())->supports('https://v.test/publishers/anthropic/models/c:RAWPREDICT'), 'Vertex method names are case-insensitive');
    }

    public function testRequestPayloadKeepsEveryField(): void
    {
        $payload = ['model' => 'm', 'messages' => [], 'stream' => true];

        self::assertSame($payload, (new AnthropicPayloadParser())->requestPayload('https://api.anthropic.com/v1/messages', $payload));
        self::assertSame($payload, (new OllamaPayloadParser())->requestPayload('http://localhost:11434/api/chat', $payload));
        self::assertSame($payload, (new OpenAiResponsesPayloadParser())->requestPayload('https://api.openai.com/v1/responses', $payload));
    }

    public function testTheResponseModelWinsOverTheRequestedOne(): void
    {
        foreach ([new AnthropicPayloadParser(), new OllamaPayloadParser(), new OpenAiResponsesPayloadParser()] as $parser) {
            self::assertSame('served', $parser->parse(['model' => 'requested'], ['model' => 'served'], 0.0)->model, $parser->name());
        }
    }

    public function testProviderSpecificFieldsAreReadPrecisely(): void
    {
        self::assertSame('chat', (new OllamaPayloadParser())->parse([], ['message' => ['content' => 'chat'], 'response' => 'generate'], 0.0)->assistantContent);

        $bedrock = (new BedrockConversePayloadParser())->parse([], ['usage' => ['inputTokens' => 1, 'cacheReadInputTokens' => 10, 'cacheWriteInputTokens' => 100, 'outputTokens' => 2]], 0.0);
        self::assertSame([111, 2, 0], [$bedrock->promptTokens, $bedrock->completionTokens, $bedrock->reasoningTokens]);

        $responses = (new OpenAiResponsesPayloadParser())->parse([], ['output' => [['type' => 'message', 'content' => [
            ['type' => 'output_text', 'text' => 'Hello, '],
            ['type' => 'output_text', 'text' => 'world'],
        ]]]], 0.0);
        self::assertSame('Hello, world', $responses->assistantContent);
    }

    public function testNumericHelpersOnlyAcceptNumbers(): void
    {
        self::assertSame([1.5, 0.0, 0.0, 2.0], [Payload::float('1.5'), Payload::float('abc'), Payload::float(null), Payload::float(2)]);
        self::assertSame([0, 7], [Payload::int('x1'), Payload::int(7.9)]);
        self::assertSame([0, 0, 0.0, 0.0], [Payload::int([1]), Payload::int(true), Payload::float([1]), Payload::float(true)]);
        self::assertSame([['a' => 1]], Payload::maps(['k' => ['a' => 1]]), 'maps() always returns a list');
    }
}
