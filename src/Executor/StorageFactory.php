<?php

declare(strict_types=1);

namespace OpenRuntimes\Executor;

use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Utopia\DSN\DSN;
use Utopia\Psr18\StreamingClientInterface;
use Utopia\Storage\Device;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\DeviceType;

class StorageFactory
{
    /**
     * Get storage device by connection string
     *
     * @param string $root Root path for storage
     * @param ?string $connection DSN connection string. An empty or null connection means no object storage is configured for this deployment and yields the local device.
     * @param (ClientInterface&StreamingClientInterface)|null $client HTTP client for the remote devices, defaulting to the one utopia-php/storage builds
     *
     * @throws InvalidArgumentException When the connection string cannot be parsed or names a scheme with no device
     */
    public static function getDevice(string $root, ?string $connection = '', (ClientInterface&StreamingClientInterface)|null $client = null): Device
    {
        if ($connection === null || $connection === '') {
            return new Local($root);
        }

        try {
            $dsn = new DSN($connection);
        } catch (\Throwable $throwable) {
            throw new InvalidArgumentException('Unable to parse storage DSN: ' . $throwable->getMessage(), previous: $throwable);
        }

        $scheme = $dsn->getScheme();
        $deviceType = DeviceType::tryFrom($scheme)
            ?? throw new InvalidArgumentException(sprintf("Storage DSN scheme '%s' has no device", $scheme));

        $accessKey = $dsn->getUser() ?? '';
        $secretKey = $dsn->getPassword() ?? '';
        $bucket = $dsn->getPath() ?? '';
        $region = $dsn->getParam('region');

        return match ($deviceType) {
            DeviceType::Local => new Local($root),
            DeviceType::S3 => new S3($root, $accessKey, $secretKey, self::getEndpoint($dsn, $bucket), $region, client: $client),
            DeviceType::AwsS3 => new AWS($root, $accessKey, $secretKey, $bucket, $region, client: $client),
            DeviceType::DoSpaces => new DOSpaces($root, $accessKey, $secretKey, $bucket, $region, client: $client),
            DeviceType::Backblaze => new Backblaze($root, $accessKey, $secretKey, $bucket, $region, client: $client),
            DeviceType::Linode => new Linode($root, $accessKey, $secretKey, $bucket, $region, client: $client),
            DeviceType::Wasabi => new Wasabi($root, $accessKey, $secretKey, $bucket, $region, client: $client),
        };
    }

    /**
     * Endpoint every object key hangs off, as `scheme://host[:port][/bucket]`.
     *
     * The bucket is appended for path-style addressing and left out when the host already
     * carries it as its leading label, which is how the branded devices address their buckets.
     */
    private static function getEndpoint(DSN $dsn, string $bucket): string
    {
        $url = $dsn->getParam('url');

        if ($url !== '') {
            return $url;
        }

        $host = $dsn->getHost();
        $port = $dsn->getPort();
        $endpoint = ($dsn->getParam('insecure') === 'true' ? 'http://' : 'https://')
            . $host
            . ($port === null || $port === '' ? '' : ':' . $port);

        if ($bucket === '' || \str_starts_with($host, $bucket . '.')) {
            return $endpoint;
        }

        return $endpoint . '/' . $bucket;
    }
}
