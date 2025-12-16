<?php

namespace OpenRuntimes\Executor\Runner;

final class Runtime
{
    /**
     * Create a new runtime instance
     *
     * @param string $version     The version of the runtime.
     * @param float  $created     The timestamp when the runtime was created.
     * @param float  $updated     The timestamp when the runtime was last updated.
     * @param string $name        The name of the runtime.
     * @param string $hostname    The hostname of the runtime.
     * @param string $status      The status of the runtime. e.g. `pending` or `Up 10s`
     * @param string $key         The secret key of the runtime.
     * @param int    $listening   The number of listeners for the runtime.
     * @param string $image       The container image of the runtime.
     * @param int    $initialised The number of initialisations for the runtime.
     */
    public function __construct(
        public string $version,
        public float $created,
        public float $updated,
        public string $name,
        public string $hostname,
        public string $status,
        public string $key,
        public int $listening,
        public string $image,
        public int $initialised
    ) {
    }

    /**
     * Converts the runtime instance to a string-indexed array.
     *
     * @return array{
     *     version: string,
     *     created: float,
     *     updated: float,
     *     name: string,
     *     hostname: string,
     *     status: string,
     *     key: string,
     *     listening: int,
     *     image: string,
     *     initialised: int
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'created' => $this->created,
            'updated' => $this->updated,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'status' => $this->status,
            'key' => $this->key,
            'listening' => $this->listening,
            'image' => $this->image,
            'initialised' => $this->initialised
        ];
    }

    /**
     * Converts a string-indexed array to a runtime instance.
     *
     * @param array{
     *     version: string,
     *     created: float,
     *     updated: float,
     *     name: string,
     *     hostname: string,
     *     status: string,
     *     key: string,
     *     listening: int,
     *     image: string,
     *     initialised: int
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['version'],
            $data['created'],
            $data['updated'],
            $data['name'],
            $data['hostname'],
            $data['status'],
            $data['key'],
            $data['listening'],
            $data['image'],
            $data['initialised']
        );
    }
}
