<?php

namespace Tests\E2E;

use OpenRuntimes\Executor\BodyMultipart;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Adapter\Curl\Client as Curl;
use Utopia\Client\Client as HttpClient;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Psr7\Stream\Factory as StreamFactory;

class Client
{
    public const string METHOD_GET = Method::GET;

    public const string METHOD_POST = Method::POST;

    public const string METHOD_PUT = Method::PUT;

    public const string METHOD_PATCH = Method::PATCH;

    public const string METHOD_DELETE = Method::DELETE;

    public const string METHOD_HEAD = Method::HEAD;

    public const string METHOD_OPTIONS = Method::OPTIONS;

    private const int MAX_REDIRECTS = 5;

    private const int CONNECT_TIMEOUT_MS = 5000;

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
    public function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, ?callable $callback = null, int $timeout = 60000): array
    {
        $client = new HttpClient(new Curl())
            ->withTimeout($timeout / 1000)
            ->withConnectTimeout(self::CONNECT_TIMEOUT_MS / 1000)
            ->withFollowRedirects(true, self::MAX_REDIRECTS);

        $request = $this->request($method, $this->endpoint . $path, $headers, $params);

        if ($callback !== null) {
            $response = $client->stream($request, function (string $chunk) use ($callback): void {
                $callback($chunk);
            });
        } else {
            $response = $client->sendRequest($request);
        }

        $responseHeaders = $this->headers($response);

        $body = null;
        if ($callback === null) {
            if ($decode) {
                $contentType = $responseHeaders['content-type'] ?? '';
                $strpos = strpos($contentType, ';');
                $strpos = is_bool($strpos) ? strlen($contentType) : $strpos;
                $contentType = substr($contentType, 0, $strpos);

                switch ($contentType) {
                    case 'multipart/form-data':
                        $boundary = explode('boundary=', $responseHeaders['content-type'] ?? '')[1] ?? '';
                        $multipartResponse = new BodyMultipart($boundary);
                        $multipartResponse->load((string) $response->getBody());
                        $body = $multipartResponse->getParts();
                        break;
                    case 'application/json':
                        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                        break;
                    default:
                        $body = (string) $response->getBody();
                        break;
                }
            } else {
                $body = (string) $response->getBody();
            }
        }

        return [
            'headers' => array_merge(
                $responseHeaders,
                ['status-code' => $response->getStatusCode()]
            ),
            'body' => $body
        ];
    }

    /**
     * Header names are case-insensitive, so the last value sent under any casing wins.
     * GET parameters go in the query string, anything else is a JSON, form or
     * multipart body chosen by the request's content type. A multipart body keeps
     * the content type the factory sets, since that one carries the boundary.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $params
     */
    private function request(string $method, string $url, array $headers, array $params): RequestInterface
    {
        $merged = [];
        foreach ([...$this->baseHeaders, ...$headers] as $key => $value) {
            $merged[strtolower($key)] = $value;
        }

        if ($method === self::METHOD_GET && $params !== []) {
            $url = rtrim($url, '?&');
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        $factory = new RequestFactory();
        $request = $factory->createRequest($method, $url);

        if ($method !== self::METHOD_GET && ($merged['content-type'] ?? '') === ContentType::MULTIPART_FORM_DATA) {
            unset($merged['content-type']);
            $request = $factory->multipart($method, $url, self::flatten($params));
        } elseif ($method !== self::METHOD_GET) {
            $body = match ($merged['content-type'] ?? '') {
                ContentType::JSON => json_encode($params, JSON_THROW_ON_ERROR),
                ContentType::FORM_URLENCODED => http_build_query($params),
                default => $params === [] ? '' : throw new \InvalidArgumentException('Unsupported content type for a request body: ' . ($merged['content-type'] ?? '(none)')),
            };
            $request = $request->withBody(new StreamFactory()->createStream($body));
        }

        foreach ($merged as $key => $value) {
            $request = $request->withHeader($key, $value);
        }

        return $request;
    }

    /**
     * Nested parameters become `parent[child]` fields and null an empty field, as
     * curl sent them.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, scalar>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $output = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

            if (is_array($value)) {
                $output += self::flatten($value, $name);
            } elseif (is_scalar($value)) {
                $output[$name] = $value;
            } elseif ($value === null) {
                $output[$name] = '';
            } else {
                throw new \InvalidArgumentException('Unsupported multipart field value for ' . $name);
            }
        }

        return $output;
    }

    /**
     * Lowercased names, keeping the last value of a repeated header.
     *
     * @return array<string, string>
     */
    private function headers(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) end($values);
        }

        return $headers;
    }
}
