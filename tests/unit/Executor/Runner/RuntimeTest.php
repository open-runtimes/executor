<?php

namespace OpenRuntimes\Executor\Tests\Unit\Executor\Runner;

use OpenRuntimes\Executor\Runner\Runtime;
use PHPUnit\Framework\TestCase;

class RuntimeTest extends TestCase
{
    public function testConstructor(): void
    {
        $time = microtime(true);

        $runtime = new Runtime(
            version: 'v5',
            created: $time,
            updated: $time,
            name: 'runtime1',
            hostname: 'runtime1',
            status: 'pending',
            key: 'secret',
            listening: 0,
            image: 'php',
            initialised: 0,
        );

        $this->assertSame('v5', $runtime->version);
        $this->assertSame($time, $runtime->created);
        $this->assertSame($time, $runtime->updated);
        $this->assertSame('runtime1', $runtime->name);
        $this->assertSame('runtime1', $runtime->hostname);
        $this->assertSame('pending', $runtime->status);
        $this->assertSame('secret', $runtime->key);
        $this->assertSame(0, $runtime->listening);
        $this->assertSame('php', $runtime->image);
        $this->assertSame(0, $runtime->initialised);
    }

    public function testFromArray(): void
    {
        $time = microtime(true);
        $runtime = Runtime::fromArray([
            'version' => 'v5',
            'created' => $time,
            'updated' => $time,
            'name' => 'runtime1',
            'hostname' => 'runtime1',
            'status' => 'pending',
            'key' => 'secret',
            'listening' => 0,
            'image' => 'php',
            'initialised' => 0
        ]);

        $this->assertSame('v5', $runtime->version);
        $this->assertSame($time, $runtime->created);
        $this->assertSame($time, $runtime->updated);
        $this->assertSame('runtime1', $runtime->name);
        $this->assertSame('runtime1', $runtime->hostname);
        $this->assertSame('pending', $runtime->status);
        $this->assertSame('secret', $runtime->key);
        $this->assertSame(0, $runtime->listening);
        $this->assertSame('php', $runtime->image);
        $this->assertSame(0, $runtime->initialised);
    }

    public function testToArray(): void
    {
        $time = microtime(true);
        $runtime = new Runtime(
            version:'v5',
            created: $time,
            updated: $time,
            name: 'runtime1',
            hostname: 'runtime1',
            status: 'pending',
            key: 'secret',
            listening: 0,
            image: 'php',
            initialised: 0,
        );
        $runtime = $runtime->toArray();

        $this->assertSame('v5', $runtime['version']);
        $this->assertSame($time, $runtime['created']);
        $this->assertSame($time, $runtime['updated']);
        $this->assertSame('runtime1', $runtime['name']);
        $this->assertSame('runtime1', $runtime['hostname']);
        $this->assertSame('pending', $runtime['status']);
        $this->assertSame('secret', $runtime['key']);
        $this->assertSame(0, $runtime['listening']);
        $this->assertSame('php', $runtime['image']);
        $this->assertSame(0, $runtime['initialised']);
    }
}
