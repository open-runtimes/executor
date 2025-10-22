<?php

namespace OpenRuntimes\Executor;

use Exception;
use Utopia\Console;
use Utopia\Orchestration\Orchestration;
use Utopia\System\System;

use function Swoole\Coroutine\batch;

class Usage
{
    protected ?float $hostUsage = null;

    /**
     * @var array<string,float>
     */
    protected array $containerUsage = [];

    public function __construct(protected Orchestration $orchestration)
    {
    }

    public function run(): void
    {
        $this->hostUsage = null;
        $this->containerUsage = [];

        batch([
            fn () => $this->runHost(),
            fn () => $this->runContainers()
        ]);
    }

    protected function runHost(): void
    {
        try {
            $this->hostUsage = System::getCPUUsage(2);
        } catch (Exception $err) {
            Console::warning('Skipping host stats loop due to error: ' . $err->getMessage());
        }
    }

    protected function runContainers(): void
    {
        try {
            $containerUsages = $this->orchestration->getStats(
                filters: [ 'label' => 'openruntimes-executor=' . System::getHostname() ]
            );

            foreach ($containerUsages as $containerUsage) {
                $hostnameArr = \explode('-', $containerUsage->getContainerName());
                \array_shift($hostnameArr);
                $hostname = \implode('-', $hostnameArr);

                $this->containerUsage[$hostname] = $containerUsage->getCpuUsage() * 100;
            }
        } catch (Exception $err) {
            Console::warning('Skipping runtimes stats loop due to error: ' . $err->getMessage());
        }
    }

    public function getHostUsage(): ?float
    {
        return $this->hostUsage;
    }

    public function getRuntimeUsage(string $runtimeId): ?float
    {
        return $this->containerUsage[$runtimeId] ?? null;
    }

    /**
     * @return array<string,float>
     */
    public function getRuntimesUsage(): array
    {
        return $this->containerUsage;
    }
}
