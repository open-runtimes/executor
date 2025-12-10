<?php

namespace OpenRuntimes\Executor;

use Utopia\Console;
use Utopia\DSN\DSN;
use Utopia\Storage\Device;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Storage as UtopiaStorage;
use Utopia\System\System;

class Storage
{
    /**
     * Get storage device based on configuration
     *
     * @param string $root
     * @return Device
     */
    public function getDevice(string $root, string $connection = ''): Device
    {
        // Fallback to environment variables if no connection string
        if (empty($connection)) {
            return $this->getDeviceFromEnv($root);
        }

        // Parse DSN connection
        $acl = 'private';
        $device = UtopiaStorage::DEVICE_LOCAL;
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
            case UtopiaStorage::DEVICE_S3:
                if (!empty($host)) {
                    $host = $insecure ? 'http://' . $host : $host;
                    return new S3(root: $root, accessKey: $accessKey, secretKey: $accessSecret, host: $host, region: $dsnRegion, acl: $acl);
                }
                return new AWS(root: $root, accessKey: $accessKey, secretKey: $accessSecret, bucket: $bucket, region: $dsnRegion, acl: $acl);

            case UtopiaStorage::DEVICE_DO_SPACES:
                return new DOSpaces($root, $accessKey, $accessSecret, $bucket, $dsnRegion, $acl);

            case UtopiaStorage::DEVICE_BACKBLAZE:
                return new Backblaze($root, $accessKey, $accessSecret, $bucket, $dsnRegion, $acl);

            case UtopiaStorage::DEVICE_LINODE:
                return new Linode($root, $accessKey, $accessSecret, $bucket, $dsnRegion, $acl);

            case UtopiaStorage::DEVICE_WASABI:
                return new Wasabi($root, $accessKey, $accessSecret, $bucket, $dsnRegion, $acl);

            case UtopiaStorage::DEVICE_LOCAL:
            default:
                return new Local($root);
        }
    }

    /**
     * Get storage device from environment variables (fallback)
     *
     * @param string $root
     * @return Device
     */
    private function getDeviceFromEnv(string $root): Device
    {
        switch (strtolower(System::getEnv('OPR_EXECUTOR_STORAGE_DEVICE', UtopiaStorage::DEVICE_LOCAL) ?? '')) {
            case UtopiaStorage::DEVICE_LOCAL:
            default:
                return new Local($root);

            case UtopiaStorage::DEVICE_S3:
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

            case UtopiaStorage::DEVICE_DO_SPACES:
                $doSpacesAccessKey = System::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_ACCESS_KEY', '') ?? '';
                $doSpacesSecretKey = System::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_SECRET', '') ?? '';
                $doSpacesRegion = System::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_REGION', '') ?? '';
                $doSpacesBucket = System::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_BUCKET', '') ?? '';
                $doSpacesAcl = 'private';
                return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);

            case UtopiaStorage::DEVICE_BACKBLAZE:
                $backblazeAccessKey = System::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_ACCESS_KEY', '') ?? '';
                $backblazeSecretKey = System::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_SECRET', '') ?? '';
                $backblazeRegion = System::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_REGION', '') ?? '';
                $backblazeBucket = System::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_BUCKET', '') ?? '';
                $backblazeAcl = 'private';
                return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);

            case UtopiaStorage::DEVICE_LINODE:
                $linodeAccessKey = System::getEnv('OPR_EXECUTOR_STORAGE_LINODE_ACCESS_KEY', '') ?? '';
                $linodeSecretKey = System::getEnv('OPR_EXECUTOR_STORAGE_LINODE_SECRET', '') ?? '';
                $linodeRegion = System::getEnv('OPR_EXECUTOR_STORAGE_LINODE_REGION', '') ?? '';
                $linodeBucket = System::getEnv('OPR_EXECUTOR_STORAGE_LINODE_BUCKET', '') ?? '';
                $linodeAcl = 'private';
                return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);

            case UtopiaStorage::DEVICE_WASABI:
                $wasabiAccessKey = System::getEnv('OPR_EXECUTOR_STORAGE_WASABI_ACCESS_KEY', '') ?? '';
                $wasabiSecretKey = System::getEnv('OPR_EXECUTOR_STORAGE_WASABI_SECRET', '') ?? '';
                $wasabiRegion = System::getEnv('OPR_EXECUTOR_STORAGE_WASABI_REGION', '') ?? '';
                $wasabiBucket = System::getEnv('OPR_EXECUTOR_STORAGE_WASABI_BUCKET', '') ?? '';
                $wasabiAcl = 'private';
                return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
        }
    }
}
