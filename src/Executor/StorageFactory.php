<?php

namespace OpenRuntimes\Executor;

use Utopia\Console;
use Utopia\DSN\DSN;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Device;
use Utopia\Storage\Storage;

class StorageFactory
{
    /**
     * Get storage device by connection string
     *
     * @param string $root Root path for storage
     * @param string $connection DSN connection string. If empty, the local device will be used.
     * @return Device
     */
    public function getDevice(string $root, string $connection = ''): Device
    {
        $acl = 'private';
        $deviceType = Storage::DEVICE_LOCAL;
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

        } catch (\Exception $e) {
            Console::warning($e->getMessage() . ' - Invalid DSN. Defaulting to Local device.');
        }

        switch ($deviceType) {
            case Storage::DEVICE_S3:
                if (!empty($url)) {
                    return new S3($root, $accessKey, $accessSecret, $url, $dsnRegion, $acl);
                }
                if (!empty($host)) {
                    $host = $insecure ? 'http://' . $host : $host;
                    return new S3(root: $root, accessKey: $accessKey, secretKey: $accessSecret, host: $host, region: $dsnRegion, acl: $acl);
                }
                return new AWS(root: $root, accessKey: $accessKey, secretKey: $accessSecret, bucket: $bucket, region: $dsnRegion, acl: $acl);

            case Storage::DEVICE_DO_SPACES:
                return new DOSpaces($root, $accessKey, $accessSecret, $bucket, $dsnRegion, $acl);

            case Storage::DEVICE_BACKBLAZE:
                return new Backblaze($root, $accessKey, $accessSecret, $bucket, $dsnRegion, $acl);

            case Storage::DEVICE_LINODE:
                return new Linode($root, $accessKey, $accessSecret, $bucket, $dsnRegion, $acl);

            case Storage::DEVICE_WASABI:
                return new Wasabi($root, $accessKey, $accessSecret, $bucket, $dsnRegion, $acl);

            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
        }
    }
}
