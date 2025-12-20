<?php

namespace OpenRuntimes\Executor\Tests\Unit\Executor\Runner\Repository;

use OpenRuntimes\Executor\Runner\Repository\Runtimes;
use OpenRuntimes\Executor\Runner\Runtime;
use PHPUnit\Framework\TestCase;

class RuntimesTest extends TestCase
{
    public function testSet(): void
    {
        $repository = new Runtimes(16);
        $runtime = new Runtime(
            version: 'v1',
            created: 1000.0,
            updated: 1001.0,
            name: 'runtime-one',
            hostname: 'runtime-one.host',
            status: 'pending',
            key: 'key-runtime-one',
            listening: 0,
            image: 'image-runtime-one',
            initialised: 0,
        );

        $repository->set('rt-1', $runtime);

        $this->assertTrue($repository->exists('rt-1'));

        $stored = $repository->get('rt-1');

        $this->assertInstanceOf(Runtime::class, $stored);
        $this->assertSame($runtime->toArray(), $stored->toArray());

    }

    public function testEmpty(): void
    {
        $repository = new Runtimes(16);
        $this->assertNull($repository->get('missing'));
    }

    public function testRemove(): void
    {
        $repository = new Runtimes(16);
        $runtime = new Runtime(
            version: 'v2',
            created: 1010.0,
            updated: 1011.0,
            name: 'runtime-two',
            hostname: 'runtime-two.host',
            status: 'pending',
            key: 'key-runtime-two',
            listening: 0,
            image: 'image-runtime-two',
            initialised: 0,
        );

        $repository->set('rt-2', $runtime);

        $this->assertTrue($repository->remove('rt-2'));
        $this->assertFalse($repository->exists('rt-2'));
        $this->assertNull($repository->get('rt-2'));

        $this->assertFalse($repository->remove('rt-2'));
    }

    public function testIteration(): void
    {
        $repository = new Runtimes(16);
        $runtimeOne = new Runtime(
            version: 'v1',
            created: 1100.0,
            updated: 1101.0,
            name: 'runtime-one',
            hostname: 'runtime-one.host',
            status: 'pending',
            key: 'key-runtime-one',
            listening: 0,
            image: 'image-runtime-one',
            initialised: 0,
        );
        $runtimeTwo = new Runtime(
            version: 'v2',
            created: 1200.0,
            updated: 1201.0,
            name: 'runtime-two',
            hostname: 'runtime-two.host',
            status: 'pending',
            key: 'key-runtime-two',
            listening: 0,
            image: 'image-runtime-two',
            initialised: 0,
        );

        $repository->set('rt-1', $runtimeOne);
        $repository->set('rt-2', $runtimeTwo);

        $collected = [];

        foreach ($repository as $id => $runtime) {
            $this->assertInstanceOf(Runtime::class, $runtime);
            $collected[$id] = $runtime->toArray();
        }

        $expected = [
            'rt-1' => $runtimeOne->toArray(),
            'rt-2' => $runtimeTwo->toArray()
        ];

        ksort($collected);
        ksort($expected);

        $this->assertSame($expected, $collected);
    }

}
