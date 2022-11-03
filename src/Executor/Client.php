<?php

namespace OpenRuntimes\Executor;

use Exception;
use Utopia\App;

// TODO: @Meldiron There should be no getEnv in here

class Client
{
    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_PATCH = 'PATCH';
    public const METHOD_DELETE = 'DELETE';
    public const METHOD_HEAD = 'HEAD';
    public const METHOD_OPTIONS = 'OPTIONS';
    public const METHOD_CONNECT = 'CONNECT';
    public const METHOD_TRACE = 'TRACE';

    private string $endpoint;

    private bool $selfSigned = false;

    /**
     * @var array<string,mixed>
     */
    protected array $headers = [
        'content-type' => '',
    ];

    public function __construct(string $endpoint)
    {
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new Exception('Unsupported endpoint');
        }
        $this->endpoint = $endpoint;
    }

    /**
     * Create runtime
     *
     * Launches a runtime container for a deployment ready for execution
     *
     * @param string $runtimeId
     * @param string $source
     * @param string $image
     * @param bool $remove
     * @param string $entrypoint
     * @param string $workdir
     * @param string $destinaction
     * @param string $network
     * @param array $variables
     * @param array $commands
     */
    public function createRuntime(
        string $runtimeId,
        string $source,
        string $image,
        bool $remove = false,
        string $entrypoint = '',
        string $workdir = '',
        string $destination = '',
        array $variables = [],
        array $commands = [],
        string $key = null
    ) {
        $route = "/runtimes";
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-executor-key' => $key ?? App::getEnv('OPEN_RUNTIMES_EXECUTOR_SECRET', '')
        ];
        $params = [
            'runtimeId' => $runtimeId,
            'source' => $source,
            'destination' => $destination,
            'image' => $image,
            'entrypoint' => $entrypoint,
            'workdir' => $workdir,
            'variables' => $variables,
            'remove' => $remove,
            'commands' => $commands
        ];

        $timeout  = (int) App::getEnv('OPEN_RUNTIMES_EXECUTOR_BUILD_TIMEOUT', 900);

        $response = $this->call(self::METHOD_POST, $route, $headers, $params, true, $timeout);

        $status = $response['headers']['status-code'];
        if ($status >= 400) {
            throw new \Exception($response['body']['message'], $status);
        }

        return $response['body'];
    }

    /**
     * Delete Runtime
     *
     * Deletes a runtime and cleans up any containers remaining.
     *
     * @param string $runtimeId
     */
    public function deleteRuntime(string $runtimeId)
    {
        $route = "/runtimes/$runtimeId";
        $headers = [
            'content-type' =>  'application/json',
            'x-appwrite-executor-key' => App::getEnv('OPEN_RUNTIMES_EXECUTOR_SECRET', '')
        ];

        $params = [];

        $response = $this->call(self::METHOD_DELETE, $route, $headers, $params, true, 30);

        $status = $response['headers']['status-code'];
        if ($status >= 400) {
            throw new \Exception($response['body']['message'], $status);
        }

        return $response['body'];
    }

    /**
     * Create an execution
     *
     * @param string $runtimeId
     * @param string $path
     * @param array $variables
     * @param string $entrypoint
     * @param string $data
     * @param string $image
     * @param int $timeout
     *
     * @return array
     */
    public function createExecution(
        string $runtimeId,
        string $path,
        array $variables,
        string $entrypoint,
        string $data,
        string $image,
        $timeout
    ) {
        $route = "/execution";
        $headers = [
            'content-type' =>  'application/json',
            'x-appwrite-executor-key' => App::getEnv('OPEN_RUNTIMES_EXECUTOR_SECRET', '')
        ];
        $params = [
            // execution-related
            'runtimeId' => $runtimeId,
            'variables' => $variables,
            'data' => $data,
            'timeout' => $timeout,

            // runtime-related
            'source' => $path,
            'image' => $image,
            'entrypoint' => $entrypoint
        ];

        /* Add 2 seconds as a buffer to the actual timeout value since there can be a slight variance*/
        $requestTimeout  = $timeout + 2;

        $response = $this->call(self::METHOD_POST, $route, $headers, $params, true, $requestTimeout);
        $status = $response['headers']['status-code'];

        switch (true) {
            case $status < 400:
                return $response['body'];
            default:
                throw new \Exception($response['body']['message'], $status);
        }

        throw new Exception($response['body']['message'], 503);
    }

    /**
     * Call
     *
     * Make an API call
     *
     * @param string $method
     * @param string $path
     * @param array $params
     * @param array $headers
     * @param bool $decode
     * @return array|string
     * @throws Exception
     */
    public function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, int $timeout = 15)
    {
        $headers            = array_merge($this->headers, $headers);
        $ch                 = curl_init($this->endpoint . $path . (($method == self::METHOD_GET && !empty($params)) ? '?' . http_build_query($params) : ''));
        $responseHeaders    = [];
        $responseStatus     = -1;
        $responseType       = '';
        $responseBody       = '';

        if ($ch === false) {
            throw new Exception('Could not prepare CURL request.', 500);
        }

        switch ($headers['content-type']) {
            case 'application/json':
                $query = json_encode($params);
                break;

            case 'multipart/form-data':
                $query = $this->flatten($params);
                break;

            default:
                $query = http_build_query($params);
                break;
        }

        foreach ($headers as $i => $header) {
            $headers[] = $i . ':' . $header;
            unset($headers[$i]);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);

            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });

        if ($method != self::METHOD_GET) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }

        // Allow self signed certificates
        if ($this->selfSigned) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $responseBody   = curl_exec($ch);
        $responseType   = $responseHeaders['content-type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($decode) {
            switch (substr($responseType, 0, strpos($responseType, ';'))) {
                case 'application/json':
                    $json = json_decode($responseBody, true);

                    if ($json === null) {
                        throw new Exception('Failed to parse response: ' . $responseBody);
                    }

                    $responseBody = $json;
                    $json = null;
                    break;
            }
        }

        if ((curl_errno($ch)/* || 200 != $responseStatus*/)) {
            $errorCode = $responseStatus > 0 ? $responseStatus : 500;
            $errorMessage = curl_error($ch) . ' with status code ' . $errorCode;


            if (curl_errno($ch) === 28) {
                $errorCode = 500;
                $errorMessage = 'Execution timed out after ' . $timeout . ' seconds.';
            }

            throw new Exception($errorMessage, $errorCode);
        }

        curl_close($ch);

        $responseHeaders['status-code'] = $responseStatus;

        return [
            'headers' => $responseHeaders,
            'body' => $responseBody
        ];
    }

    /**
     * Parse Cookie String
     *
     * @param string $cookie
     * @return array
     */
    public function parseCookie(string $cookie): array
    {
        $cookies = [];

        parse_str(strtr($cookie, array('&' => '%26', '+' => '%2B', ';' => '&')), $cookies);

        return $cookies;
    }

    /**
     * Flatten params array to PHP multiple format
     *
     * @param array $data
     * @param string $prefix
     * @return array
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $output = [];

        foreach ($data as $key => $value) {
            $finalKey = $prefix ? "{$prefix}[{$key}]" : $key;

            if (is_array($value)) {
                $output += $this->flatten($value, $finalKey); // @todo: handle name collision here if needed
            } else {
                $output[$finalKey] = $value;
            }
        }

        return $output;
    }
}
