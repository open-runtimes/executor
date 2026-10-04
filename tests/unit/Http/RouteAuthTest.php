<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

final class RouteAuthTest extends TestCase
{
    public function testNonPublicRoutesDeclareApiGroup(): void
    {
        $controllers = \dirname(__DIR__, 3) . '/app/controllers.php';
        $source = \file_get_contents($controllers);
        if ($source === false) {
            $this->fail('Unable to read app/controllers.php');
        }

        $matches = [];
        \preg_match_all(
            "/Http::(?:get|post|put|patch|delete)\\('([^']+)'\\)(.*?)(?=Http::(?:get|post|put|patch|delete|init|error)\\()/s",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($matches, 'Expected HTTP routes in app/controllers.php');

        $paths = [];
        foreach ($matches as $match) {
            $path = $match[1] ?? '';
            $body = $match[2] ?? '';
            $paths[] = $path;

            if ($path === '/v1/health') {
                $this->assertDoesNotMatchRegularExpression(
                    "/->groups\\(\\[[^\\]]*?'api'/",
                    $body,
                    'Health must stay public for Docker healthchecks'
                );
                continue;
            }

            $this->assertMatchesRegularExpression(
                "/->groups\\(\\[[^\\]]*?'api'/",
                $body,
                "Route {$path} must declare the api group so the executor secret check applies"
            );
        }

        $this->assertContains('/v1/runtimes/:runtimeId/commands', $paths);
        $this->assertContains('/v1/health', $paths);
    }
}
