<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Tests\Unit;

use Axitdev\PhpUnitAiEvals\Vcr\SecretScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretScrubberTest extends TestCase
{
    public function testRedactsSensitiveHeadersCaseInsensitively(): void
    {
        $scrubbed = (new SecretScrubber())->scrubHeaders([
            'Authorization' => ['Bearer sk-123'],
            'X-API-Key' => 'abc',
            'api-key' => ['k'],
            'OpenAI-Organization' => ['org-1'],
            'x-goog-api-key' => ['g'],
            'Cookie' => ['sid=1'],
            'Set-Cookie' => ['a=1', 'b=2'],
            'anthropic-organization-id' => ['org-uuid'],
            'OpenAI-Project' => ['proj_1'],
            'Content-Type' => ['application/json'],
        ]);

        self::assertSame([
            'Authorization' => ['[REDACTED]'],
            'X-API-Key' => ['[REDACTED]'],
            'api-key' => ['[REDACTED]'],
            'OpenAI-Organization' => ['[REDACTED]'],
            'x-goog-api-key' => ['[REDACTED]'],
            'Cookie' => ['[REDACTED]'],
            'Set-Cookie' => ['[REDACTED]'],
            'anthropic-organization-id' => ['[REDACTED]'],
            'OpenAI-Project' => ['[REDACTED]'],
            'Content-Type' => ['application/json'],
        ], $scrubbed);
    }

    #[DataProvider('uris')]
    public function testScrubUri(string $input, string $expected): void
    {
        self::assertSame($expected, (new SecretScrubber())->scrubUri($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function uris(): iterable
    {
        yield 'no query' => ['https://a.test/v1/x', 'https://a.test/v1/x'];
        yield 'key param' => ['https://a.test/x?key=SECRET&alt=sse', 'https://a.test/x?key=[REDACTED]&alt=sse'];
        yield 'case-insensitive' => ['https://a.test/x?API_KEY=s', 'https://a.test/x?API_KEY=[REDACTED]'];
        yield 'fragment kept' => ['https://a.test/x?token=s#frag', 'https://a.test/x?token=[REDACTED]#frag'];
        yield 'harmless params untouched' => ['https://a.test/x?q=1&page=2', 'https://a.test/x?q=1&page=2'];
        yield 'valueless param' => ['https://a.test/x?flag&key=s', 'https://a.test/x?flag&key=[REDACTED]'];
        yield 'userinfo' => ['https://u:p@a.test/x?q=1', 'https://[REDACTED]@a.test/x?q=1'];
        yield 'at sign in query is not userinfo' => ['https://a.test/x?email=a@b.test', 'https://a.test/x?email=a@b.test'];
    }

    public function testExtraHeadersAndKeysAddToTheBuiltInLists(): void
    {
        $scrubber = new SecretScrubber(['X-Tenant-Token'], ['Session_Id']);

        self::assertSame(
            ['x-tenant-token' => ['[REDACTED]'], 'Authorization' => ['[REDACTED]']],
            $scrubber->scrubHeaders(['x-tenant-token' => 't', 'Authorization' => 'a']),
        );
        self::assertSame('https://a.test/x?session_id=[REDACTED]&key=[REDACTED]', $scrubber->scrubUri('https://a.test/x?session_id=s&key=k'));
        self::assertSame('{"session_id":"[REDACTED]","password":"[REDACTED]"}', $scrubber->scrubBody('{"session_id":"s","password":"p"}'));
    }

    #[DataProvider('bodies')]
    public function testScrubBody(string $input, string $expected): void
    {
        self::assertSame($expected, (new SecretScrubber())->scrubBody($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodies(): iterable
    {
        yield 'json nested' => ['{"auth":{"Password":"p","user":"u"},"key":"k"}', '{"auth":{"Password":"[REDACTED]","user":"u"},"key":"k"}'];
        yield 'json untouched keeps formatting' => ["{\n  \"q\": 1\n}", "{\n  \"q\": 1\n}"];
        yield 'form' => ['grant_type=refresh_token&refresh_token=r&scope=a+b', 'grant_type=refresh_token&refresh_token=[REDACTED]&scope=a+b'];
        yield 'plain text' => ['token = not a form', 'token = not a form'];
        yield 'empty' => ['', ''];
    }

    public function testUnlistedCredentialNamesAreCaughtByTheirWords(): void
    {
        $scrubber = new SecretScrubber();
        $headers = [
            'x-portkey-api-key', 'Ocp-Apim-Subscription-Key', 'cf-aig-authorization', 'X-Auth-Token', 'X-CSRF-Token',
            'x-ratelimit-remaining-tokens', 'anthropic-ratelimit-input-tokens-limit', 'Access-Control-Allow-Credentials', 'X-Token-Count',
            'x-request-id', 'keep-alive', 'X-Monkey',
        ];
        $scrubbed = $scrubber->scrubHeaders(array_fill_keys($headers, 'v'));

        self::assertSame(
            ['x-portkey-api-key', 'Ocp-Apim-Subscription-Key', 'cf-aig-authorization', 'X-Auth-Token', 'X-CSRF-Token'],
            array_keys(array_filter($scrubbed, static fn(array $v): bool => $v === ['[REDACTED]'])),
        );
        self::assertSame(
            '{"session_token":"[REDACTED]","aws_secret_access_key":"[REDACTED]","private_key":"[REDACTED]","credentials":"[REDACTED]","max_tokens":5,"key":"k","monkey":"m"}',
            $scrubber->scrubBody('{"session_token":"s","aws_secret_access_key":"a","private_key":"p","credentials":"c","max_tokens":5,"key":"k","monkey":"m"}'),
        );
        self::assertSame('https://a.test/x?client_assertion=[REDACTED]&tokens=3', $scrubber->scrubUri('https://a.test/x?client_assertion=c&tokens=3'));
        self::assertSame(
            ['token_count' => 5, 'auth_required' => true, 'password' => false, 'secret' => null, 'api_key_id' => 'k1'],
            $scrubber->scrubData(['token_count' => 5, 'auth_required' => true, 'password' => false, 'secret' => null, 'api_key_id' => 'k1']),
            'names that only start with a credential word, and flags, stay readable',
        );
    }

    public function testHeaderValuesBecomeAStringList(): void
    {
        self::assertSame(['X-Retry' => ['1', '2']], (new SecretScrubber())->scrubHeaders(['X-Retry' => ['a' => '1', 'b' => '2']]));
    }

    public function testQueryEndsExactlyAtTheFragmentAndProseIsNotAForm(): void
    {
        $scrubber = new SecretScrubber();

        self::assertSame('https://a.test/x?a=1&b=2#frag', $scrubber->scrubUri('https://a.test/x?a=1&b=2#frag'));
        self::assertSame('password=hunter2 and some prose', $scrubber->scrubBody('password=hunter2 and some prose'));
        self::assertSame('{"token":"[REDACTED]","note":"café/ok"}', $scrubber->scrubBody('{"token":"t","note":"café/ok"}'));
    }
}
