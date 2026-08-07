<?php

declare(strict_types=1);

namespace Tests\Unit\Executor;

use InvalidArgumentException;
use OpenRuntimes\Executor\StorageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr18\StreamingClientInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;

final class StorageFactoryTest extends TestCase
{
    private const string OBJECT_KEY = 'wal-archive/db-abc/000000010000000000000001';

    /**
     * @return \Iterator<string, array{string, string, string}>
     */
    public static function connections(): \Iterator
    {
        yield 'path-style with a port' => [
            's3://key:secret@host.internal:30000/storage?insecure=true',
            self::OBJECT_KEY,
            'http://host.internal:30000/storage/wal-archive/db-abc/000000010000000000000001',
        ];
        yield 'path-style without a port' => [
            's3://key:secret@s3.amazonaws.com/backups?region=us-east-1',
            self::OBJECT_KEY,
            'https://s3.amazonaws.com/backups/wal-archive/db-abc/000000010000000000000001',
        ];
        yield 'virtual-hosted dospaces' => [
            'dospaces://key:secret@fra1.digitaloceanspaces.com/appwrite-backups?region=fra1',
            self::OBJECT_KEY,
            'https://appwrite-backups.fra1.digitaloceanspaces.com/wal-archive/db-abc/000000010000000000000001',
        ];
        yield 'virtual-hosted s3 does not repeat the bucket' => [
            's3://key:secret@mybucket.s3.us-east-1.amazonaws.com/mybucket?region=us-east-1',
            self::OBJECT_KEY,
            'https://mybucket.s3.us-east-1.amazonaws.com/wal-archive/db-abc/000000010000000000000001',
        ];
        yield 'explicit url carrying the bucket' => [
            's3://key:secret@localhost/mybucket?region=garage&url=' . \urlencode('http://127.0.0.1:3900/mybucket'),
            self::OBJECT_KEY,
            'http://127.0.0.1:3900/mybucket/wal-archive/db-abc/000000010000000000000001',
        ];
        yield 'explicit url without the bucket resolves the same' => [
            's3://key:secret@localhost/mybucket?region=garage&url=' . \urlencode('http://127.0.0.1:3900'),
            self::OBJECT_KEY,
            'http://127.0.0.1:3900/mybucket/wal-archive/db-abc/000000010000000000000001',
        ];
        yield 'deployed minio connection' => [
            's3://user:password@minio.edge.svc.cluster.local/storage?insecure=true',
            self::OBJECT_KEY,
            'http://minio.edge.svc.cluster.local/storage/wal-archive/db-abc/000000010000000000000001',
        ];
        yield 'no bucket leaves the key to name it' => [
            's3://user:password@minio.edge.svc.cluster.local?insecure=true',
            self::OBJECT_KEY,
            'http://minio.edge.svc.cluster.local/wal-archive/db-abc/000000010000000000000001',
        ];
        yield 'a bucket named zero is a bucket' => [
            's3://user:password@minio.edge.svc.cluster.local/0?insecure=true',
            self::OBJECT_KEY,
            'http://minio.edge.svc.cluster.local/0/wal-archive/db-abc/000000010000000000000001',
        ];
    }

    #[DataProvider('connections')]
    public function testConnectionResolvesToObjectUrl(string $connection, string $objectKey, string $url): void
    {
        $client = new class () implements ClientInterface, StreamingClientInterface {
            public string $url = '';

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->url = (string) $request->getUri();

                return new Response(200);
            }

            public function stream(RequestInterface $request, callable $sink): ResponseInterface
            {
                return $this->sendRequest($request);
            }
        };

        StorageFactory::getDevice('/', $connection, $client)
            ->write($objectKey, new Stream('wal'), 'application/octet-stream');

        $this->assertSame($url, $client->url);
    }

    public function testRootedDeviceAddressesTheSameObjectThroughGetPath(): void
    {
        $client = new class () implements ClientInterface, StreamingClientInterface {
            public string $url = '';

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->url = (string) $request->getUri();

                return new Response(200);
            }

            public function stream(RequestInterface $request, callable $sink): ResponseInterface
            {
                return $this->sendRequest($request);
            }
        };

        $device = StorageFactory::getDevice('/build-cache', 's3://user:password@minio.edge.svc.cluster.local/storage?insecure=true', $client);
        $device->write($device->getPath('cache-key/lz4-b1M/stores.sqfs'), new Stream('artifact'), 'application/octet-stream');

        $this->assertSame(
            'http://minio.edge.svc.cluster.local/storage/build-cache/cache-key/lz4-b1M/stores.sqfs',
            $client->url
        );
    }

    public function testPathStyleConnectionPutsBucketAndPortOnTheWire(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertNotFalse($server, 'Unable to bind a local listener: ' . $errorMessage);

        $address = (string) \stream_socket_get_name($server, false);
        $port = (int) \substr($address, \strrpos($address, ':') + 1);

        $script = <<<'PHP'
        require $argv[1];
        \OpenRuntimes\Executor\StorageFactory::getDevice('/', $argv[2])
            ->write($argv[3], new \Utopia\Psr7\Stream('wal'), 'application/octet-stream');
        PHP;

        $process = \proc_open(
            [
                PHP_BINARY,
                '-r',
                $script,
                \dirname(__DIR__, 3) . '/vendor/autoload.php',
                sprintf('s3://key:secret@127.0.0.1:%d/backups?region=fra&insecure=true', $port),
                self::OBJECT_KEY,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertNotFalse($process, 'Unable to start the writer process');

        $connection = \stream_socket_accept($server, 30);
        $this->assertNotFalse($connection, 'The device never connected to the endpoint the DSN names');

        $head = '';
        while (!\str_contains($head, "\r\n\r\n")) {
            $chunk = \fread($connection, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $head .= $chunk;
        }

        \fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        \fclose($connection);
        \fclose($server);
        foreach ($pipes as $pipe) {
            \fclose($pipe);
        }

        \proc_terminate($process);
        \proc_close($process);

        $this->assertStringStartsWith('PUT /backups/' . self::OBJECT_KEY . " HTTP/1.1\r\n", $head);
        $this->assertStringContainsString("\r\nhost: 127.0.0.1:{$port}\r\n", \strtolower($head));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function unusableConnections(): \Iterator
    {
        yield 'no host' => ['s3://accessKey:secret@/mybucket?region=garage'];
        yield 'no scheme' => ['accessKey:secret@minio/mybucket'];
        yield 'unparseable' => ['s3://accessKey:secret@minio:port/mybucket'];
        yield 'unknown scheme' => ['ftp://accessKey:secret@minio/mybucket'];
        yield 'misspelled scheme' => ['s3x://accessKey:secret@minio/mybucket'];
    }

    #[DataProvider('unusableConnections')]
    public function testUnusableConnectionIsRefused(string $connection): void
    {
        $this->expectException(InvalidArgumentException::class);

        StorageFactory::getDevice('/storage/builds/app-test', $connection);
    }

    /**
     * @return \Iterator<string, array{(string | null)}>
     */
    public static function unconfiguredConnections(): \Iterator
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'documented file scheme' => ['file://localhost'];
        yield 'file scheme with no host' => ['file:///tmp'];
        yield 'local scheme' => ['local://localhost'];
    }

    #[DataProvider('unconfiguredConnections')]
    public function testConnectionWithoutObjectStorageResolvesToLocalDevice(?string $connection): void
    {
        $device = StorageFactory::getDevice('/storage/builds/app-test', $connection);

        $this->assertInstanceOf(Local::class, $device);
        $this->assertSame('/storage/builds/app-test', $device->getRoot());
    }

    public function testRootIsNotRewrittenByTheBucket(): void
    {
        $device = StorageFactory::getDevice('/storage/builds/app-test', 's3://accessKey:secret@minio/mybucket?region=us-east-1&insecure=true');

        $this->assertSame('/storage/builds/app-test', $device->getRoot());
        $this->assertSame('/storage/builds/app-test/artifact.tar.gz', $device->getPath('artifact.tar.gz'));
    }

    public function testDeviceTypeFollowsTheScheme(): void
    {
        $devices = [
            's3' => 's3://key:secret@minio/mybucket?region=fra&insecure=true',
            'awss3' => 'awss3://key:secret@s3.amazonaws.com/mybucket?region=us-east-1',
            'dospaces' => 'dospaces://key:secret@fra1.digitaloceanspaces.com/mybucket?region=fra1',
            'backblaze' => 'backblaze://key:secret@backblazeb2.com/mybucket?region=us-west-004',
            'linode' => 'linode://key:secret@linodeobjects.com/mybucket?region=eu-central-1',
            'wasabi' => 'wasabi://key:secret@wasabisys.com/mybucket?region=eu-central-1',
            'local' => 'local://localhost/storage',
        ];

        foreach ($devices as $type => $connection) {
            $device = StorageFactory::getDevice('/', $connection);

            $this->assertInstanceOf(Device::class, $device);
            $this->assertSame($type, $device->getType()->value, sprintf("Scheme '%s' resolved to the wrong device", $type));
        }
    }
}
