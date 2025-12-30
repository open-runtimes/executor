<?php

namespace OpenRuntimes\Executor\Runner;

use Utopia\Console;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Orchestration;

use function Swoole\Coroutine\batch;

class NetworkManager
{
    /** @var string[] Networks available for use */
    private array $available = [];

    /**
     * @param string[] $networks Networks to ensure exist
     */
    public function __construct(
        private readonly Orchestration $orchestration,
        array $networks,
    ) {
        if (empty($networks)) {
            return;
        }

        $jobs = array_map(
            fn (string $network) => fn (): ?string => $this->ensure($network),
            $networks
        );

        $this->available = array_values(array_filter(
            batch($jobs),
            fn ($v) => \is_string($v) && $v !== ''
        ));
    }

    /** @return string[] */
    public function getAvailable(): array
    {
        return $this->available;
    }

    public function connectAll(Container $container): void
    {
        foreach ($this->available as $network) {
            try {
                $this->orchestration->networkConnect($container->getName(), $network);
            } catch (\Throwable) {
                // TODO: Orchestration library should throw a distinct exception for "already connected"
            }
        }
    }

    public function removeAll(): void
    {
        if (empty($this->available)) {
            return;
        }

        batch(array_map(
            fn ($network) => fn () => $this->remove($network),
            $this->available
        ));
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
}
