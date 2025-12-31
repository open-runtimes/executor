<?php

namespace OpenRuntimes\Executor\Runner;

use Utopia\Console;
use Utopia\Orchestration\Orchestration;

use function Swoole\Coroutine\batch;

readonly class ImagePuller
{
    public function __construct(private Orchestration $orchestration)
    {
    }

    /**
     * Pulls images from the registry.
     *
     * @param list<string> $images
     */
    public function pull(array $images): void
    {
        if (empty($images)) {
            Console::log('[ImagePuller] No images to pull.');
            return;
        }

        $jobs = array_map(fn ($image) => function () use ($image) {
            if (!$this->orchestration->pull($image)) {
                Console::error("[ImagePuller] Failed to pull image $image");
                return;
            }

            return true;
        }, $images);

        go(function () use ($jobs) {
            $results = batch($jobs);
            $success = \count(array_filter($results));

            Console::info("[ImagePuller] Pulled $success/". \count($jobs) . " images.");
        });

        Console::info('[ImagePuller] Started pulling images.');
    }
}
