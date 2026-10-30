<?php

declare(strict_types=1);

namespace Axitdev\PhpUnitAiEvals\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends a request for real from inside an Http::fake() callback, where calling Laravel's client again would only hit
 * the fake. Transport options of the original call (timeouts, proxy, TLS) are kept; the request itself already
 * carries the URL, headers and body.
 *
 * @internal
 */
final class PassthroughClient implements ClientInterface
{
    private const TRANSPORT_OPTIONS = [
        'timeout', 'connect_timeout', 'read_timeout', 'verify', 'cert', 'ssl_key', 'proxy', 'version',
        'allow_redirects', 'decode_content', 'force_ip_resolve', 'idn_conversion', 'curl', 'stream_context',
    ];

    /** @var array<string, mixed> */
    private array $options = [];

    public function __construct(private readonly GuzzleClientInterface $guzzle = new Client()) {}

    /**
     * @param array<array-key, mixed> $options Guzzle options Laravel passed to the fake callback
     */
    public function useOptions(array $options): void
    {
        $this->options = array_intersect_key($options, array_flip(self::TRANSPORT_OPTIONS));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Error statuses are answers to record, not exceptions; Laravel raises them later if the caller asks.
        return $this->guzzle->send($request, ['http_errors' => false] + $this->options);
    }
}
