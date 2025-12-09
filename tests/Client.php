<?php

namespace Tests;

use Utopia\Fetch\Client as FetchClient;
use OpenRuntimes\Executor\BodyMultipart;

class Client extends FetchClient
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $key,
        private readonly array $baseHeaders = []
    ) {
    }

    public function setKey(string $key): void
    {
        $this->baseHeaders['Authorization'] = 'Bearer ' . $key;
    }

    /**
     * Wrapper method for client calls to make requests to the executor
     *
     * @param string $method
     * @param string $path
     * @param array<string, string> $headers
     * @param array<string, mixed> $params
     * @param bool $decode
     * @param ?callable $callback
     * @return array<string, mixed>
     */
    public function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, ?callable $callback = null): array
    {
        $url = $this->endpoint . $path;

        $client = new FetchClient();
        $client->setTimeout(60);

        foreach ($this->baseHeaders as $key => $value) {
            $client->addHeader($key, $value);
        }
        foreach ($headers as $key => $value) {
            $client->addHeader($key, $value);
        }

        $response = $client->fetch(
            url: $url,
            method: $method,
            body: $method !== FetchClient::METHOD_GET ? $params : [],
            query: $method === FetchClient::METHOD_GET ? $params : [],
            chunks: $callback ? function ($chunk) use ($callback) {
                $callback($chunk->getData());
            } : null
        );

        $body = null;
        if ($callback === null) {
            if ($decode) {
                $contentType = $response->getHeaders()['content-type'] ?? '';
                $strpos = strpos($contentType, ';');
                $strpos = is_bool($strpos) ? strlen($contentType) : $strpos;
                $contentType = substr($contentType, 0, $strpos);

                switch ($contentType) {
                    case 'multipart/form-data':
                        $boundary = explode('boundary=', $response->getHeaders()['content-type'] ?? '')[1] ?? '';
                        $multipartResponse = new BodyMultipart($boundary);
                        $multipartResponse->load($response->text());
                        $body = $multipartResponse->getParts();
                        break;
                    case 'application/json':
                        $body = $response->json();
                        break;
                    default:
                        $body = $response->text();
                        break;
                }
            } else {
                $body = $response->text();
            }
        }

        $result = [
            'headers' => array_merge(
                $response->getHeaders(),
                ['status-code' => $response->getStatusCode()]
            ),
            'body' => $body
        ];

        return $result;
    }
}
