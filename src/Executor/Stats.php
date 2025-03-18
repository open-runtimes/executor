<?php

namespace OpenRuntimes\Executor;

use Swoole\Table;

class Stats
{
    private Table $host;
    private Table $containers;

    public function __construct()
    {
        $this->host = new Table(4096);
        $this->host->column('usage', Table::TYPE_FLOAT, 8);
        $this->host->create();

        $this->containers = new Table(4096);
        $this->containers->column('usage', Table::TYPE_FLOAT, 8);
        $this->containers->create();
    }

    public function getHostUsage(): ?float
    {
        return $this->host->get('host', 'usage') ?? null;
    }

    /**
     * @return mixed[]
     */
    public function getContainerUsage(): array
    {
        $data = [];
        foreach ($this->containers as $hostname => $stat) {
            $data[$hostname] = [
                'status' => 'pass',
                'usage'  => $stat['usage'] ?? null
            ];
        }
        return $data;
    }

    public function updateStats(Usage $usage): void
    {
        // Update host usage stats
        if ($usage->getHostUsage() !== null) {
            $oldStat = $this->getHostUsage();

            if ($oldStat === null) {
                $stat = $usage->getHostUsage();
            } else {
                $stat = ($oldStat + $usage->getHostUsage()) / 2;
            }

            $this->host->set('host', ['usage' => $stat]);
        }

        // Update runtime usage stats
        foreach ($usage->getRuntimesUsage() as $runtime => $usageStat) {
            $oldStat = $this->containers->get($runtime, 'usage') ?? null;

            if ($oldStat === null) {
                $stat = $usageStat;
            } else {
                $stat = ($oldStat + $usageStat) / 2;
            }

            $this->containers->set($runtime, ['usage' => $stat]);
        }

        // Delete gone runtimes
        $runtimes = \array_keys($usage->getRuntimesUsage());
        foreach ($this->containers as $hostname => $stat) {
            if (!(\in_array($hostname, $runtimes))) {
                $this->containers->delete($hostname);
            }
        }
    }
}
