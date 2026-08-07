<?php

declare(strict_types=1);

namespace OpenRuntimes\Executor;

use Utopia\Console;
use Utopia\DSN\DSN;
use Utopia\Storage\Acl;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Device;
use Utopia\Storage\DeviceType;

class StorageFactory
{
    /**
     * Get storage device by connection string
     *
     * @param string $root Root path for storage
     * @param ?string $connection DSN connection string. If empty or null, the local device will be used.
     */
    public static function getDevice(string $root, ?string $connection = ''): Device
    {
        $connection ??= '';
        $acl = Acl::Private;
        $deviceType = DeviceType::Local->value;
        $accessKey = '';
        $accessSecret = '';
        $host = '';
        $bucket = '';
        $dsnRegion = '';
        $insecure = false;
        $url = '';

        try {
            $dsn = new DSN($connection);
            $deviceType = $dsn->getScheme();
            $accessKey = $dsn->getUser() ?? '';
            $accessSecret = $dsn->getPassword() ?? '';
            $host = $dsn->getHost();
            $bucket = $dsn->getPath() ?? '';
            $dsnRegion = $dsn->getParam('region');
            $insecure = $dsn->getParam('insecure', 'false') === 'true';
            $url = $dsn->getParam('url', '');

        } catch (\Throwable $throwable) {
            Console::warning($throwable->getMessage() . ' - Invalid DSN. Defaulting to Local device.');
        }

        switch (DeviceType::tryFrom($deviceType)) {
            case DeviceType::S3:
                $bucketRoot = ($bucket === '' || $bucket === '0')
                    ? $root
                    : \rtrim($bucket . '/' . \ltrim($root, '/'), '/');

                if ($url !== '' && $url !== '0') {
                    return new S3($bucketRoot, $accessKey, $accessSecret, $url, $dsnRegion, $acl);
                }

                if ($host !== '' && $host !== '0') {
                    $host = $insecure ? 'http://' . $host : $host;
                    return new S3(root: $bucketRoot, accessKey: $accessKey, secretKey: $accessSecret, host: $host, region: $dsnRegion, acl: $acl);
                }

                return new AWS(root: $root, accessKey: $accessKey, secretKey: $accessSecret, bucket: $bucket, region: $dsnRegion, acl: $acl);

            case DeviceType::DoSpaces:
                return new DOSpaces($root, $accessKey, $accessSecret, $bucket, $dsnRegion, $acl);

            case DeviceType::Backblaze:
                return new Backblaze($root, $accessKey, $accessSecret, $bucket, $dsnRegion, $acl);

            case DeviceType::Linode:
                return new Linode($root, $accessKey, $accessSecret, $bucket, $dsnRegion, $acl);

            case DeviceType::Wasabi:
                return new Wasabi($root, $accessKey, $accessSecret, $bucket, $dsnRegion, $acl);

            default:
                return new Local($root);
        }
    }
}
