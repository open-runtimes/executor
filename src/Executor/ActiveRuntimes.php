<?php

namespace OpenRuntimes\Executor;

/**
 * @phpstan-type ActiveRuntime array{id: string, name: string, hostname: string, status: string, key: string, created: float, updated: float}
*/
class ActiveRuntimes
{
    /**
     * @var array<string, ActiveRuntime>
     */
    protected array $runtimes = [];

    /**
     * @param ActiveRuntime $runtime
     */
    public function set(string $id, array $runtime): self
    {
        $this->runtimes[$id] = $runtime;
        return $this;
    }

    /**
     * @return ActiveRuntime|null
     */
    public function get(string $id): ?array
    {
        return $this->runtimes[$id] ?? null;
    }

    /**
     * @return array<string, ActiveRuntime>
     */
    public function getAll(): array
    {
        return $this->runtimes;
    }

    public function exists(string $id): bool
    {
        return \array_key_exists($id, $this->runtimes);
    }

    public function del(string $id): self
    {
        unset($this->runtimes[$id]);
        return $this;
    }
}
