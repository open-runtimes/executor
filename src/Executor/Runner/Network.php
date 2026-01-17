<?php

namespace OpenRuntimes\Executor\Runner;

use Utopia\Console;
use Utopia\Orchestration\Orchestration;

class Network
{
    /** @var string[] Networks that are available */
    private array $available = [];

    /** @var string|null Name of the container linked to the networks */
    private ?string $container = null;

    public function __construct(
        private readonly Orchestration $orchestration,
    ) {
    }

    /**
     * @param string[] $networks Networks to ensure exist
     * @param string $container Name of the container to link to the networks
     */
    public function setup(array $networks, string $container): void
    {
        foreach ($networks as $network) {
            /* Ensure network exists */
            if ($this->orchestration->networkExists($network)) {
                Console::info(sprintf('[Network] Network %s already exists', $network));
            } else {
                try {
                    $this->orchestration->createNetwork($network, false);
                    Console::success('[Network] Created network: ' . $network);
                } catch (\Throwable $e) {
                    Console::error(sprintf('[Network] Failed to create network %s: %s', $network, $e->getMessage()));
                    continue;
                }
            }

            $this->available[] = $network;

            /* Add the container */
            try {
                $this->orchestration->networkConnect($container, $network);
                $this->container = $container;
            } catch (\Throwable $e) {
                Console::error(sprintf('[Network] Failed to connect container %s to network %s: %s', $container, $network, $e->getMessage()));
            }
        }
    }

    /**
     * Remove a network and disconnect a container from it.
     */
    public function cleanup(): void
    {
        foreach ($this->available as $network) {
            if ($this->container !== null) {
                /* Remove the container */
                try {
                    $this->orchestration->networkDisconnect($this->container, $network);
                } catch (\Throwable $e) {
                    Console::error(sprintf('[Network] Failed to disconnect container %s from network %s: %s', $this->container, $network, $e->getMessage()));
                }
            }

            /* Ensure network exists */
            if ($this->orchestration->networkExists($network)) {
                Console::info(sprintf('[Network] Network %s already exists', $network));
            } else {
                try {
                    $this->orchestration->removeNetwork($network);
                    Console::success('[Network] Deleted network: ' . $network);
                } catch (\Throwable $e) {
                    Console::error(sprintf('[Network] Failed to delete network %s: %s', $network, $e->getMessage()));
                }
            }
        }
    }

    /**
     * @return string[] Networks that are available
     */
    public function getAvailable(): array
    {
        return $this->available;
    }
}
