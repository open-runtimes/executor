<?php

namespace OpenRuntimes\Executor\Runner\Repository;

use Iterator;
use OpenRuntimes\Executor\Runner\Runtime;
use Swoole\Table;

/**
 * @implements Iterator<string, Runtime>
 */
final readonly class Runtimes implements Iterator
{
    private Table $runtimes;

    /**
     * Create a runtime repository.
     * This class should be initialized in the main application process, before workers are forked.
     *
     * @param int $size The size of the table. Swoole tables must be preallocated.
     */
    public function __construct(int $size = 4096)
    {
        $this->runtimes = new Table($size);

        $this->runtimes->column('version', Table::TYPE_STRING, 32);
        $this->runtimes->column('created', Table::TYPE_FLOAT);
        $this->runtimes->column('updated', Table::TYPE_FLOAT);
        $this->runtimes->column('name', Table::TYPE_STRING, 1024);
        $this->runtimes->column('hostname', Table::TYPE_STRING, 1024);
        $this->runtimes->column('status', Table::TYPE_STRING, 256);
        $this->runtimes->column('key', Table::TYPE_STRING, 1024);
        $this->runtimes->column('listening', Table::TYPE_INT, 1);
        $this->runtimes->column('image', Table::TYPE_STRING, 1024);
        $this->runtimes->column('initialised', Table::TYPE_INT, 0);

        $this->runtimes->create();
    }

    /**
     * Get a runtime by ID.
     *
     * @param  string $id The ID of the runtime to retrieve.
     * @return Runtime|null The runtime object or null if not found.
     */
    public function get(string $id): ?Runtime
    {
        $runtime = $this->runtimes->get($id);
        if ($runtime === false) {
            return null;
        }

        return Runtime::fromArray($runtime);
    }

    /**
     * Check if a runtime exists by ID.
     *
     * @param  string $id The ID of the runtime to check.
     * @return bool True if the runtime exists, false otherwise.
     */
    public function exists(string $id): bool
    {
        return $this->runtimes->exists($id);
    }

    /**
     * Set a runtime by ID. Existing runtime will be overwritten.
     *
     * @param  string  $id The ID of the runtime to update.
     * @param  Runtime $runtime The updated runtime object.
     */
    public function set(string $id, Runtime $runtime): void
    {
        $this->runtimes->set($id, $runtime->toArray());
    }

    /**
     * Remove a runtime by ID.
     *
     * @param  string $id The ID of the runtime to remove.
     * @return bool True if the runtime was removed, false otherwise.
     */
    public function remove(string $id): bool
    {
        return $this->runtimes->del($id);
    }

    /**
     * List all runtimes.
     *
     * @return array<Runtime> An array of Runtime objects.
     */
    public function list(): array
    {
        $runtimes = [];
        foreach ($this->runtimes as $runtimeKey => $runtime) {
            $runtimes[$runtimeKey] = Runtime::fromArray($runtime);
        }

        return $runtimes;
    }

    // Iterator traits
    public function current(): Runtime
    {
        $runtime = $this->runtimes->current();
        return Runtime::fromArray($runtime);
    }

    public function next(): void
    {
        $this->runtimes->next();
    }

    public function key(): string
    {
        return $this->runtimes->key();
    }

    public function valid(): bool
    {
        return $this->runtimes->valid();
    }

    public function rewind(): void
    {
        $this->runtimes->rewind();
    }
}
