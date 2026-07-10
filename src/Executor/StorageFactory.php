<?php

declare(strict_types=1);

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
use Utopia\System\System;

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

        // Appwrite compose still ships OPR_EXECUTOR_CONNECTION_STORAGE=local://localhost
        // while configuring remote storage via OPR_EXECUTOR_STORAGE_* env vars.
        if (self::shouldUseEnvFallback($connection)) {
            return self::getDeviceFromEnv($root);
        }

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
            $bucket = \ltrim($dsn->getPath() ?? '', '/');
            $dsnRegion = $dsn->getParam('region');
            $insecure = $dsn->getParam('insecure', 'false') === 'true';
            $url = $dsn->getParam('url', '');

        } catch (\Throwable $throwable) {
            Console::warning($throwable->getMessage() . ' - Invalid DSN. Defaulting to Local device.');
        }

        switch ($deviceType) {
            case Storage::DEVICE_S3:
                if ($url !== '' && $url !== '0') {
                    return new S3(self::withBucketRoot($root, $bucket), $accessKey, $accessSecret, $url, $dsnRegion, $acl);
                }

                if ($host !== '' && $host !== '0') {
                    $host = $insecure ? 'http://' . $host : $host;
                    return new S3(root: self::withBucketRoot($root, $bucket), accessKey: $accessKey, secretKey: $accessSecret, host: $host, region: $dsnRegion, acl: $acl);
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

    /**
     * Fall back to OPR_EXECUTOR_STORAGE_* when CONNECTION_STORAGE is empty or the local default.
     */
    private static function shouldUseEnvFallback(string $connection): bool
    {
        $device = \strtolower(System::getEnv('OPR_EXECUTOR_STORAGE_DEVICE', Storage::DEVICE_LOCAL) ?? Storage::DEVICE_LOCAL);
        if ($device === '' || $device === Storage::DEVICE_LOCAL) {
            return false;
        }

        if ($connection === '') {
            return true;
        }

        return \in_array($connection, [
            'local://localhost',
            'file://localhost',
            'local://',
            'file://',
        ], true);
    }

    /**
     * Get storage device from legacy OPR_EXECUTOR_STORAGE_* environment variables.
     */
    private static function getDeviceFromEnv(string $root): Device
    {
        $acl = 'private';

        switch (\strtolower(System::getEnv('OPR_EXECUTOR_STORAGE_DEVICE', Storage::DEVICE_LOCAL) ?? Storage::DEVICE_LOCAL)) {
            case Storage::DEVICE_S3:
                $accessKey = System::getEnv('OPR_EXECUTOR_STORAGE_S3_ACCESS_KEY', '') ?? '';
                $secretKey = System::getEnv('OPR_EXECUTOR_STORAGE_S3_SECRET', '') ?? '';
                $host = System::getEnv('OPR_EXECUTOR_STORAGE_S3_HOST', '') ?? '';
                $region = System::getEnv('OPR_EXECUTOR_STORAGE_S3_REGION', '') ?? '';
                $bucket = System::getEnv('OPR_EXECUTOR_STORAGE_S3_BUCKET', '') ?? '';
                $endpointUrl = System::getEnv('OPR_EXECUTOR_STORAGE_S3_ENDPOINT', '') ?? '';

                if ($endpointUrl !== '' && $endpointUrl !== '0') {
                    return new S3(self::withBucketRoot($root, $bucket), $accessKey, $secretKey, $endpointUrl, $region, $acl);
                }

                if ($host !== '' && $host !== '0') {
                    return new S3(root: self::withBucketRoot($root, $bucket), accessKey: $accessKey, secretKey: $secretKey, host: $host, region: $region, acl: $acl);
                }

                return new AWS(root: $root, accessKey: $accessKey, secretKey: $secretKey, bucket: $bucket, region: $region, acl: $acl);

            case Storage::DEVICE_DO_SPACES:
                return new DOSpaces(
                    $root,
                    System::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_ACCESS_KEY', '') ?? '',
                    System::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_SECRET', '') ?? '',
                    System::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_BUCKET', '') ?? '',
                    System::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_REGION', '') ?? '',
                    $acl
                );

            case Storage::DEVICE_BACKBLAZE:
                return new Backblaze(
                    $root,
                    System::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_ACCESS_KEY', '') ?? '',
                    System::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_SECRET', '') ?? '',
                    System::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_BUCKET', '') ?? '',
                    System::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_REGION', '') ?? '',
                    $acl
                );

            case Storage::DEVICE_LINODE:
                return new Linode(
                    $root,
                    System::getEnv('OPR_EXECUTOR_STORAGE_LINODE_ACCESS_KEY', '') ?? '',
                    System::getEnv('OPR_EXECUTOR_STORAGE_LINODE_SECRET', '') ?? '',
                    System::getEnv('OPR_EXECUTOR_STORAGE_LINODE_BUCKET', '') ?? '',
                    System::getEnv('OPR_EXECUTOR_STORAGE_LINODE_REGION', '') ?? '',
                    $acl
                );

            case Storage::DEVICE_WASABI:
                return new Wasabi(
                    $root,
                    System::getEnv('OPR_EXECUTOR_STORAGE_WASABI_ACCESS_KEY', '') ?? '',
                    System::getEnv('OPR_EXECUTOR_STORAGE_WASABI_SECRET', '') ?? '',
                    System::getEnv('OPR_EXECUTOR_STORAGE_WASABI_BUCKET', '') ?? '',
                    System::getEnv('OPR_EXECUTOR_STORAGE_WASABI_REGION', '') ?? '',
                    $acl
                );

            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
        }
    }

    /**
     * Generic S3 devices encode the bucket in the root path.
     */
    private static function withBucketRoot(string $root, string $bucket): string
    {
        $bucket = \ltrim($bucket, '/');

        if ($bucket === '') {
            return $root;
        }

        return $bucket . '/' . \ltrim($root, '/');
    }
}
