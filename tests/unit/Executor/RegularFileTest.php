<?php

declare(strict_types=1);

namespace Tests\Unit\Executor;

use OpenRuntimes\Executor\Logs;
use OpenRuntimes\Executor\RegularFile;
use PHPUnit\Framework\TestCase;

final class RegularFileTest extends TestCase
{
    private string $dir;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = \sys_get_temp_dir() . '/regular-file-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir);

        // Stands in for the executor's own files, such as /proc/self/environ. Two lines, because
        // build logs drop the first line as the script banner.
        $this->secret = $this->dir . '/secret';
        \file_put_contents($this->secret, "first line\nOPR_EXECUTOR_SECRET=leaked");
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);

        parent::tearDown();
    }

    public function testReadsARegularFile(): void
    {
        \file_put_contents($this->dir . '/logs.log', 'hello world');

        $this->assertSame('hello world', RegularFile::read($this->dir . '/logs.log'));
        $this->assertSame('world', RegularFile::read($this->dir . '/logs.log', 6));
        $this->assertSame('hello', RegularFile::read($this->dir . '/logs.log', 0, 5));
        $this->assertNotNull(RegularFile::stat($this->dir . '/logs.log'));
    }

    public function testDoesNotFollowASymlinkTheContainerPlanted(): void
    {
        \symlink($this->secret, $this->dir . '/logs.log');

        $this->assertSame('', RegularFile::read($this->dir . '/logs.log'));
        $this->assertNull(RegularFile::stat($this->dir . '/logs.log'));
        $this->assertStringContainsString('leaked', (string) \file_get_contents($this->secret));
    }

    public function testRefusesAFifoWithoutBlocking(): void
    {
        if (!\function_exists('posix_mkfifo')) {
            $this->markTestSkipped('posix_mkfifo is not available');
        }

        \posix_mkfifo($this->dir . '/logs.log', 0600);

        $this->assertSame('', RegularFile::read($this->dir . '/logs.log'));
        $this->assertNull(RegularFile::stat($this->dir . '/logs.log'));
    }

    public function testMissingPathReadsAsEmpty(): void
    {
        $this->assertSame('', RegularFile::read($this->dir . '/missing.log'));
        $this->assertNull(RegularFile::stat($this->dir . '/missing.log'));
    }

    public function testDetectsAFileSwappedForASymlinkAfterTheCheck(): void
    {
        $path = $this->dir . '/code.tar.gz';
        \file_put_contents($path, 'build');
        $stat = RegularFile::stat($path);
        $this->assertNotNull($stat);
        $this->assertTrue(RegularFile::unchanged($path, $stat));

        \unlink($path);
        \symlink($this->secret, $path);

        $this->assertFalse(RegularFile::unchanged($path, $stat));
    }

    public function testBuildLogsDoNotFollowASymlinkTheBuildPlanted(): void
    {
        // Logs::get reads /tmp/<container>/logging, the directory the build mounts as /tmp/logging.
        // app/init.php defines it in the executor; the unit suite doesn't load that.
        if (!\defined('MAX_BUILD_LOG_SIZE')) {
            \define('MAX_BUILD_LOG_SIZE', 1000 * 1000);
        }

        $container = 'regular-file-test-' . \bin2hex(\random_bytes(6));
        $logging = '/tmp/' . $container . '/logging';
        \mkdir($logging, 0777, true);

        try {
            \symlink($this->secret, $logging . '/logs.txt');
            \file_put_contents($logging . '/timings.txt', "0.1 30\n");

            $output = \json_encode(Logs::get($container));

            $this->assertIsString($output);
            $this->assertStringNotContainsString('leaked', $output);
        } finally {
            $this->remove('/tmp/' . $container);
        }
    }

    private function remove(string $path): void
    {
        if (\is_link($path) || \is_file($path) || \filetype($path) === 'fifo') {
            \unlink($path);
            return;
        }

        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        \rmdir($path);
    }
}
