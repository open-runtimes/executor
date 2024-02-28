<?php

namespace OpenRuntimes\Executor;

class ActiveRuntimes
{
    /**
     * @var array<string, mixed> $runtimes
     */
    protected array $runtimes = [];

    /**
     * @param array<mixed> $runtime
     */
    public function set(string $id, array $runtime): self
    {
        $this->runtimes[$id] = $runtime;
        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function get(string $id): array
    {
        return $this->runtimes[$id] ?? [];
    }

    /**
     * @return array<string, mixed>
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
