<?php

namespace Tests\Unit\Executor\Runner\Repository;

use OpenRuntimes\Executor\Runner\Repository\Runtimes;
use OpenRuntimes\Executor\Runner\Runtime;
use PHPUnit\Framework\TestCase;

class RuntimesTest extends TestCase
{
    public function testSet(): void
    {
        $repository = new Runtimes(16);
        $runtime = new Runtime(
            'v1',
            1000.0,
            1001.0,
            'runtime-one',
            'runtime-one.host',
            'pending',
            'key-runtime-one',
            0,
            'image-runtime-one',
            0
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
            'v2',
            1010.0,
            1011.0,
            'runtime-two',
            'runtime-two.host',
            'pending',
            'key-runtime-two',
            0,
            'image-runtime-two',
            0
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
            'v1',
            1100.0,
            1101.0,
            'runtime-one',
            'runtime-one.host',
            'pending',
            'key-runtime-one',
            0,
            'image-runtime-one',
            0
        );
        $runtimeTwo = new Runtime(
            'v2',
            1200.0,
            1201.0,
            'runtime-two',
            'runtime-two.host',
            'pending',
            'key-runtime-two',
            0,
            'image-runtime-two',
            0
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
