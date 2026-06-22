<?php

namespace Tests\E2E;

use OpenRuntimes\Executor\BodyMultipart;
use Swoole\Coroutine;
use Utopia\Client as HttpClient;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Adapter\SwooleCoroutine\Client as SwooleAdapter;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

class Client
{
    public const METHOD_GET = Method::GET;

    public const METHOD_POST = Method::POST;

    public const METHOD_PUT = Method::PUT;

    public const METHOD_PATCH = Method::PATCH;

    public const METHOD_DELETE = Method::DELETE;

    public const METHOD_HEAD = Method::HEAD;

    public const METHOD_OPTIONS = Method::OPTIONS;

    /**
     * @param array<string, string> $baseHeaders
     */
    public function __construct(
        private readonly string $endpoint,
        private array $baseHeaders = []
    ) {
    }

    public function setKey(string $key): void
    {
        $this->baseHeaders['Authorization'] = 'Bearer ' . $key;
    }

    /**
     * Wrapper method for client calls to make requests to the executor
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, ?callable $callback = null): array
    {
        $url = $this->endpoint . $path;
        $headers = \array_merge($this->baseHeaders, $headers);

        $factory = new RequestFactory();
        $contentType = \strtolower($headers['content-type'] ?? $headers['Content-Type'] ?? '');
        if ($method === Method::GET) {
            $request = $factory->query($method, $url, $params, $headers);
        } elseif ($contentType === 'multipart/form-data') {
            // Drop the caller's boundary-less content type so the factory emits
            // one with a boundary; otherwise the parts can't be parsed.
            $headers = \array_filter($headers, static fn (string $key): bool => \strtolower($key) !== 'content-type', ARRAY_FILTER_USE_KEY);
            $request = $factory->multipart($method, $url, $params, $headers);
        } elseif ($contentType === 'application/x-www-form-urlencoded') {
            $request = $factory->form($method, $url, $params, $headers);
        } else {
            $request = $factory->body($method, $url, \json_encode($params, JSON_THROW_ON_ERROR), $contentType !== '' ? $contentType : 'application/json', $headers);
        }

        // Outside a coroutine cURL blocks fine; inside one the Swoole adapter
        // yields so concurrent calls (e.g. streaming logs) run in parallel.
        $adapter = Coroutine::getCid() > 0 ? new SwooleAdapter() : new CurlAdapter();
        $client = new HttpClient($adapter)->withTimeout(60.0);

        if ($callback !== null) {
            $response = $client->stream($request, static fn (string $chunk) => $callback($chunk));
        } else {
            $response = $client->sendRequest($request);
        }

        $body = null;
        if ($callback === null) {
            $contentTypeHeader = $response->getHeaderLine('content-type');
            $contentType = \strtolower(\trim(\explode(';', $contentTypeHeader)[0]));
            $text = (string) $response->getBody();

            if ($decode) {
                switch ($contentType) {
                    case 'multipart/form-data':
                        $boundary = \explode('boundary=', $contentTypeHeader)[1] ?? '';
                        $multipart = new BodyMultipart($boundary);
                        $multipart->load($text);
                        $body = $multipart->getParts();
                        break;
                    case 'application/json':
                        $body = \json_decode($text, true);
                        break;
                    default:
                        $body = $text;
                        break;
                }
            } else {
                $body = $text;
            }
        }

        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[\strtolower((string) $name)] = \implode(', ', $values);
        }

        $responseHeaders['status-code'] = $response->getStatusCode();

        return [
            'headers' => $responseHeaders,
            'body' => $body
        ];
    }
}
