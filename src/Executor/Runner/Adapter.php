<?php

namespace OpenRuntimes\Executor\Runner;

use OpenRuntimes\Executor\Stats;
use Utopia\CLI\Console;
use Utopia\DSN\DSN;
use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\Logger\Log;
use Utopia\Storage\Device;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Storage;

abstract class Adapter
{
    /**
     * @param string $runtimeId
     * @param int $timeout
     * @param Response $response
     * @param Log $log
     * @return void
     */
    abstract public function getLogs(string $runtimeId, int $timeout, Response $response, Log $log): void;

    /**
     * @param string $runtimeId
     * @param string $command
     * @param int $timeout
     * @return string
     */
    abstract public function executeCommand(string $runtimeId, string $command, int $timeout): string;

    /**
     * @param string $runtimeId
     * @param string $secret
     * @param string $image
     * @param string $entrypoint
     * @param string $source
     * @param string $destination
     * @param string[] $variables
     * @param string $runtimeEntrypoint
     * @param string $command
     * @param int $timeout
     * @param bool $remove
     * @param float $cpus
     * @param int $memory
     * @param string $version
     * @param string $restartPolicy
     * @param Log $log
     * @return mixed
     */
    abstract public function createRuntime(string $runtimeId, string $secret, string $image, string $entrypoint, string $source, string $destination, array $variables, string $runtimeEntrypoint, string $command, int $timeout, bool $remove, float $cpus, int $memory, string $version, string $restartPolicy, Log $log): mixed;

    /**
     * @param string $runtimeId
     * @param Log $log
     * @return void
     */
    abstract public function deleteRuntime(string $runtimeId, Log $log): void;

    /**
     * @param string $runtimeId
     * @param string|null $payload
     * @param string $path
     * @param string $method
     * @param mixed $headers
     * @param int $timeout
     * @param string $image
     * @param string $source
     * @param string $entrypoint
     * @param mixed $variables
     * @param float $cpus
     * @param int $memory
     * @param string $version
     * @param string $runtimeEntrypoint
     * @param bool $logging
     * @param string $restartPolicy
     * @param Log $log
     * @return mixed
     */
    abstract public function createExecution(
        string $runtimeId,
        ?string $payload,
        string $path,
        string $method,
        mixed $headers,
        int $timeout,
        string $image,
        string $source,
        string $entrypoint,
        mixed $variables,
        float $cpus,
        int $memory,
        string $version,
        string $runtimeEntrypoint,
        bool $logging,
        string $restartPolicy,
        Log $log
    ): mixed;

    abstract public function getRuntimes(): mixed;

    abstract public function getRuntime(string $name): mixed;

    abstract public function getStats(): Stats;

    protected function getStorageDevice(
        string $root,
        string $region = ''
    ): Device {
        $connections = System::getEnv('OPR_EXECUTOR_CONNECTION_STORAGE', '');

        if ($connections === null) {
            $connections = '';
        }

        $connections = \explode(',', $connections);

        foreach ($connections as $connection) {
            $connection = \trim($connection);

            if (empty($connection)) {
                continue;
            }

            [$connectionRegion, $connection] = \array_pad(\explode('=', $connection, 2), 2, '');

            if ($region === $connectionRegion) {
                break;
            }
        }

        if (!empty($connection)) {
            $acl = 'private';
            $device = Storage::DEVICE_LOCAL;
            $accessKey = '';
            $accessSecret = '';
            $host = '';
            $bucket = '';
            $region = '';
            $insecure = false;

            try {
                $dsn = new DSN($connection);
                $device = $dsn->getScheme();
                $accessKey = $dsn->getUser() ?? '';
                $accessSecret = $dsn->getPassword() ?? '';
                $host = $dsn->getHost();
                $bucket = $dsn->getPath() ?? '';
                $region = $dsn->getParam('region');
                $insecure = $dsn->getParam('insecure', 'false') === 'true';
            } catch (\Exception $e) {
                Console::warning($e->getMessage() . 'Invalid DSN. Defaulting to Local device.');
            }

            switch ($device) {
                case Storage::DEVICE_S3:
                    if (!empty($host)) {
                        $host = $insecure ? 'http://' . $host : $host;
                        return new S3(root: $root, accessKey: $accessKey, secretKey: $accessSecret, host: $host, region: $region, acl: $acl);
                    } else {
                        return new AWS(root: $root, accessKey: $accessKey, secretKey: $accessSecret, bucket: $bucket, region: $region, acl: $acl);
                    }
                    // no break
                case STORAGE::DEVICE_DO_SPACES:
                    return new DOSpaces($root, $accessKey, $accessSecret, $bucket, $region, $acl);
                case Storage::DEVICE_BACKBLAZE:
                    return new Backblaze($root, $accessKey, $accessSecret, $bucket, $region, $acl);
                case Storage::DEVICE_LINODE:
                    return new Linode($root, $accessKey, $accessSecret, $bucket, $region, $acl);
                case Storage::DEVICE_WASABI:
                    return new Wasabi($root, $accessKey, $accessSecret, $bucket, $region, $acl);
                case Storage::DEVICE_LOCAL:
                default:
                    return new Local($root);
            }
        } else {
            switch (strtolower(Http::getEnv('OPR_EXECUTOR_STORAGE_DEVICE', Storage::DEVICE_LOCAL) ?? '')) {
                case Storage::DEVICE_LOCAL:
                default:
                    return new Local($root);
                case Storage::DEVICE_S3:
                    $s3AccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_ACCESS_KEY', '') ?? '';
                    $s3SecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_SECRET', '') ?? '';
                    $s3Host = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_HOST', '') ?? '';
                    $s3Region = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_REGION', '') ?? '';
                    $s3Bucket = Http::getEnv('OPR_EXECUTOR_STORAGE_S3_BUCKET', '') ?? '';
                    $s3Acl = 'private';
                    if (!empty($s3Host)) {
                        return new S3(root: $root, accessKey: $s3AccessKey, secretKey: $s3SecretKey, host: $s3Host, region: $s3Region, acl: $s3Acl);
                    } else {
                        return new AWS(root: $root, accessKey: $s3AccessKey, secretKey: $s3SecretKey, bucket: $s3Bucket, region: $s3Region, acl: $s3Acl);
                    }
                    // no break
                case Storage::DEVICE_DO_SPACES:
                    $doSpacesAccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_ACCESS_KEY', '') ?? '';
                    $doSpacesSecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_SECRET', '') ?? '';
                    $doSpacesRegion = Http::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_REGION', '') ?? '';
                    $doSpacesBucket = Http::getEnv('OPR_EXECUTOR_STORAGE_DO_SPACES_BUCKET', '') ?? '';
                    $doSpacesAcl = 'private';
                    return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
                case Storage::DEVICE_BACKBLAZE:
                    $backblazeAccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_ACCESS_KEY', '') ?? '';
                    $backblazeSecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_SECRET', '') ?? '';
                    $backblazeRegion = Http::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_REGION', '') ?? '';
                    $backblazeBucket = Http::getEnv('OPR_EXECUTOR_STORAGE_BACKBLAZE_BUCKET', '') ?? '';
                    $backblazeAcl = 'private';
                    return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
                case Storage::DEVICE_LINODE:
                    $linodeAccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_LINODE_ACCESS_KEY', '') ?? '';
                    $linodeSecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_LINODE_SECRET', '') ?? '';
                    $linodeRegion = Http::getEnv('OPR_EXECUTOR_STORAGE_LINODE_REGION', '') ?? '';
                    $linodeBucket = Http::getEnv('OPR_EXECUTOR_STORAGE_LINODE_BUCKET', '') ?? '';
                    $linodeAcl = 'private';
                    return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
                case Storage::DEVICE_WASABI:
                    $wasabiAccessKey = Http::getEnv('OPR_EXECUTOR_STORAGE_WASABI_ACCESS_KEY', '') ?? '';
                    $wasabiSecretKey = Http::getEnv('OPR_EXECUTOR_STORAGE_WASABI_SECRET', '') ?? '';
                    $wasabiRegion = Http::getEnv('OPR_EXECUTOR_STORAGE_WASABI_REGION', '') ?? '';
                    $wasabiBucket = Http::getEnv('OPR_EXECUTOR_STORAGE_WASABI_BUCKET', '') ?? '';
                    $wasabiAcl = 'private';
                    return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
            }
        }
    }
}
