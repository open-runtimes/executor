<?php

namespace OpenRuntimes\Executor\Storage;

use Utopia\Console;
use Utopia\DSN\DSN;
use Utopia\Storage\Device as StorageDevice;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Storage;
use Utopia\System\System;

class Device
{
    /**
     * Get storage device based on configuration
     *
     * @param string $root
     * @return StorageDevice
     */
    public function getStorageDevice(string $root, string $connection = ''): StorageDevice
    {
        // Fallback to environment variables if no connection string
        if (empty($connection)) {
            return $this->getStorageDeviceFromEnv($root);
        }

        // Parse DSN connection
        $acl = 'private';
        $device = Storage::DEVICE_LOCAL;
        $accessKey = '';
        $accessSecret = '';
        $host = '';
        $bucket = '';
        $dsnRegion = '';
        $insecure = false;

        try {
            $dsn = new DSN($connection);
            $device = $dsn->getScheme();
            $accessKey = $dsn->getUser() ?? '';
            $accessSecret = $dsn->getPassword() ?? '';
            $host = $dsn->getHost();
            $bucket = $dsn->getPath() ?? '';
            $dsnRegion = $dsn->getParam('region');
            $insecure = $dsn->getParam('insecure', 'false') === 'true';
        } catch (\Exception $e) {
            Console::warning($e->getMessage() . ' - Invalid DSN. Defaulting to Local device.');
        }

        switch ($device) {
            case Storage::DEVICE_S3:
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

    /**
     * Get storage device from environment variables (fallback)
     *
     * @param string $root
     * @return StorageDevice
     */
    private function getStorageDeviceFromEnv(string $root): StorageDevice
    {
        switch (strtolower(System::getEnv('OPR_EXECUTOR_STORAGE_DEVICE', Storage::DEVICE_LOCAL) ?? '')) {
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);

            case Storage::DEVICE_S3:
                $s3AccessKey = System::getEnv('OPR_EXECUTOR_STORAGE_S3_ACCESS_KEY', '') ?? '';
                $s3SecretKey = System::getEnv('OPR_EXECUTOR_STORAGE_S3_SECRET', '') ?? '';
                $s3Host = System::getEnv('OPR_EXECUTOR_STORAGE_S3_HOST', '') ?? '';
                $s3Region = System::getEnv('OPR_EXECUTOR_STORAGE_S3_REGION', '') ?? '';
                $s3Bucket = System::getEnv('OPR_EXECUTOR_STORAGE_S3_BUCKET', '') ?? '';
                $s3Acl = 'private';
                $s3EndpointUrl = System::getEnv('OPR_EXECUTOR_STORAGE_S3_ENDPOINT', '');
                if (!empty($s3EndpointUrl)) {
                    $bucketRoot = (!empty($s3Bucket) ? $s3Bucket . '/' : '') . \ltrim($root, '/');
                    return new S3($bucketRoot, $s3AccessKey, $s3SecretKey, $s3EndpointUrl, $s3Region, $s3Acl);
                }
                if (!empty($s3Host)) {
                    return new S3(root: $root, accessKey: $s3AccessKey, secretKey: $s3SecretKey, host: $s3Host, region: $s3Region, acl: $s3Acl);
                }
                return new AWS(root: $root, accessKey: $s3AccessKey, secretKey: $s3SecretKey, bucket: $s3Bucket, region: $s3Region, acl: $s3Acl);

            case Storage::DEVICE_DO_SPACES:
                $doSpacesAccessKey = System::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_ACCESS_KEY', '') ?? '';
                $doSpacesSecretKey = System::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_SECRET', '') ?? '';
                $doSpacesRegion = System::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_REGION', '') ?? '';
                $doSpacesBucket = System::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_BUCKET', '') ?? '';
                $doSpacesAcl = 'private';
                return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);

            case Storage::DEVICE_BACKBLAZE:
                $backblazeAccessKey = System::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_ACCESS_KEY', '') ?? '';
                $backblazeSecretKey = System::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_SECRET', '') ?? '';
                $backblazeRegion = System::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_REGION', '') ?? '';
                $backblazeBucket = System::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_BUCKET', '') ?? '';
                $backblazeAcl = 'private';
                return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);

            case Storage::DEVICE_LINODE:
                $linodeAccessKey = System::getEnv('OPR_EXECUTOR_STORAGE_LINODE_ACCESS_KEY', '') ?? '';
                $linodeSecretKey = System::getEnv('OPR_EXECUTOR_STORAGE_LINODE_SECRET', '') ?? '';
                $linodeRegion = System::getEnv('OPR_EXECUTOR_STORAGE_LINODE_REGION', '') ?? '';
                $linodeBucket = System::getEnv('OPR_EXECUTOR_STORAGE_LINODE_BUCKET', '') ?? '';
                $linodeAcl = 'private';
                return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);

            case Storage::DEVICE_WASABI:
                $wasabiAccessKey = System::getEnv('OPR_EXECUTOR_STORAGE_WASABI_ACCESS_KEY', '') ?? '';
                $wasabiSecretKey = System::getEnv('OPR_EXECUTOR_STORAGE_WASABI_SECRET', '') ?? '';
                $wasabiRegion = System::getEnv('OPR_EXECUTOR_STORAGE_WASABI_REGION', '') ?? '';
                $wasabiBucket = System::getEnv('OPR_EXECUTOR_STORAGE_WASABI_BUCKET', '') ?? '';
                $wasabiAcl = 'private';
                return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
        }
    }
}
