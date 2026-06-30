<?php

declare(strict_types=1);

namespace Tests\Unit\Executor\Runner;

use OpenRuntimes\Executor\Runner\Docker;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class DockerTest extends TestCase
{
    public function testBuildCommandsUseOriginalUserCommand(): void
    {
        $docker = $this->createDocker();
        $commands = $this->invokeGetBuildCommands($docker, 'echo original-user-command', 'v5');

        $this->assertSame('bash', $commands[0]);
        $this->assertStringContainsString('echo original-user-command', $commands[2]);
        $this->assertStringNotContainsString('build-cache.sh', $commands[2]);
        $this->assertStringNotContainsString('/cache/build-command.sh', $commands[2]);
    }

    public function testNoBuildCommandScriptReferenceRemains(): void
    {
        $docker = $this->createDocker();

        foreach (['v2', 'v5'] as $version) {
            $commands = $this->invokeGetBuildCommands($docker, 'echo test', $version);
            $this->assertStringNotContainsString('/cache/build-command.sh', \implode(' ', $commands));
        }
    }

    public function testNoCacheLifecycleShellCommandsAreGenerated(): void
    {
        $docker = $this->createDocker();
        $commands = $this->invokeGetBuildCommands($docker, 'npm install', 'v5');
        $command = \implode(' ', $commands);

        $this->assertStringNotContainsString('restore-build-cache.sh', $command);
        $this->assertStringNotContainsString('save-build-cache.sh', $command);
        $this->assertStringNotContainsString('build-cache-restore.sh', $command);
        $this->assertStringNotContainsString('build-cache-save.sh', $command);
    }

    public function testBuildCommandsReportSilentNonZeroExit(): void
    {
        $docker = $this->createDocker();

        foreach (['v2', 'v5'] as $version) {
            $commands = $this->invokeGetBuildCommands($docker, 'exit 1', $version);
            $command = \implode(' ', $commands);

            $this->assertStringContainsString('Build command exited with code $status.', $command);
            $this->assertStringContainsString('exit $status', $command);
        }
    }

    public function testInvalidBuildCacheKeyIsRejected(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(400);

        $method = new ReflectionMethod(Docker::class, 'validateBuildCacheKey');
        $method->invoke($this->createDocker(), '..');
    }

    /**
     * @return string[]
     */
    private function invokeGetBuildCommands(Docker $docker, string $command, string $version): array
    {
        $method = new ReflectionMethod(Docker::class, 'getBuildCommands');

        return $method->invoke($docker, $command, $version);
    }

    private function createDocker(): Docker
    {
        $reflection = new ReflectionClass(Docker::class);
        return $reflection->newInstanceWithoutConstructor();
    }
}
