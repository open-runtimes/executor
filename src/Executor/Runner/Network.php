<?php

namespace OpenRuntimes\Executor\Runner;

use Utopia\Console;
use Utopia\Orchestration\Orchestration;

class Network
{
    public function __construct(
        private readonly Orchestration $orchestration,
    ) {
    }

    /**
     * @param string[] $networks Networks to ensure exist
     * @param string $container Name of the container to link to the networks
     *
     * @return string[] Networks that were created
     */
    public function setup(array $networks, string $container): array
    {
        if (empty($networks)) {
            return [];
        }

        $available = [];
        foreach ($networks as $network) {
            $candidate = $this->ensure($network);
            if ($candidate) {
                $available[] = $candidate;
            }
        }

        foreach ($available as $network) {
            try {
                $this->orchestration->networkConnect($container, $network);
            } catch (\Throwable) {
                // TODO: (@loks0n) utopia-php/orchestration should return a duplicate/conflict exception.
            }
        }

        return $available;
    }

    private function ensure(string $network): ?string
    {
        if ($this->orchestration->networkExists($network)) {
            Console::info("[NetworkManager] Network {$network} already exists");
            return $network;
        }

        try {
            $this->orchestration->createNetwork($network, false);
            Console::success("[NetworkManager] Created network: {$network}");
            return $network;
        } catch (\Throwable $e) {
            Console::error("[NetworkManager] Failed to create network {$network}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Removes networks from the orchestration.
     *
     * @param array<string> $networks
     */
    public function removeNetworks(array $networks): void
    {
        if (empty($networks)) {
            return;
        }

        foreach ($networks as $network) {
            $this->remove($network);
        }
    }

    private function remove(string $network): void
    {
        if (!$this->orchestration->networkExists($network)) {
            Console::error("[NetworkManager] Network {$network} does not exist");
            return;
        }

        try {
            $this->orchestration->removeNetwork($network);
            Console::success("[NetworkManager] Removed network: {$network}");
        } catch (\Throwable $e) {
            Console::error("[NetworkManager] Failed to remove network {$network}: {$e->getMessage()}");
        }
    }
}
