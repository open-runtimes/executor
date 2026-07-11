<?php

declare(strict_types=1);

namespace Tests\Unit\Executor;

use OpenRuntimes\Executor\StorageFactory;
use PHPUnit\Framework\TestCase;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;

final class StorageFactoryTest extends TestCase
{
    public function testEmptyConnectionReturnsLocalDevice(): void
    {
        $device = StorageFactory::getDevice('/storage/builds/app-test', '');

        $this->assertInstanceOf(Local::class, $device);
        $this->assertSame('/storage/builds/app-test', $device->getRoot());
    }

    public function testNullConnectionReturnsLocalDevice(): void
    {
        $device = StorageFactory::getDevice('/storage/builds/app-test', null);

        $this->assertInstanceOf(Local::class, $device);
    }

    public function testInvalidDsnFallsBackToLocalDevice(): void
    {
        // Missing host is invalid for a DSN
        $device = StorageFactory::getDevice('/storage/builds/app-test', 's3://accessKey:secret@/mybucket?region=garage');

        $this->assertInstanceOf(Local::class, $device);
    }

    public function testS3WithUrlPrependsBucketToRoot(): void
    {
        $dsn = 's3://accessKey:secret@localhost/mybucket?region=garage&url=' . \urlencode('http://127.0.0.1:3900');
        $device = StorageFactory::getDevice('/storage/builds/app-test', $dsn);

        $this->assertSame(S3::class, $device::class);
        $this->assertSame('mybucket/storage/builds/app-test', $device->getRoot());
        $this->assertSame('mybucket/storage/builds/app-test/artifact.tar.gz', $device->getPath('artifact.tar.gz'));
    }

    public function testS3WithUrlAndRootSlashUsesBucketAsRoot(): void
    {
        $dsn = 's3://accessKey:secret@localhost/mybucket?region=garage&url=' . \urlencode('http://127.0.0.1:3900');
        $device = StorageFactory::getDevice('/', $dsn);

        $this->assertSame(S3::class, $device::class);
        $this->assertSame('mybucket', $device->getRoot());
    }

    public function testS3WithUrlWithoutBucketKeepsRoot(): void
    {
        $dsn = 's3://accessKey:secret@localhost?region=garage&url=' . \urlencode('http://127.0.0.1:3900');
        $device = StorageFactory::getDevice('/storage/builds/app-test', $dsn);

        $this->assertSame(S3::class, $device::class);
        $this->assertSame('/storage/builds/app-test', $device->getRoot());
    }

    public function testS3WithHostPrependsBucketToRoot(): void
    {
        $dsn = 's3://accessKey:secret@minio/mybucket?region=us-east-1&insecure=true';
        $device = StorageFactory::getDevice('/storage/builds/app-test', $dsn);

        $this->assertSame(S3::class, $device::class);
        $this->assertSame('mybucket/storage/builds/app-test', $device->getRoot());
    }

    public function testS3WithHostWithoutBucketKeepsRoot(): void
    {
        $dsn = 's3://accessKey:secret@mybucket.s3.us-east-1.amazonaws.com?region=us-east-1';
        $device = StorageFactory::getDevice('/storage/builds/app-test', $dsn);

        $this->assertSame(S3::class, $device::class);
        $this->assertSame('/storage/builds/app-test', $device->getRoot());
    }
}
