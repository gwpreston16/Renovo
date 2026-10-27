<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * An edge image built with `docker compose up -d --build` shows its commit id
 * only if compose forwards `RENOVO_BUILD` and the Dockerfile declares it.
 */
final class ComposeFilesTest extends TestCase
{
    public function testTheAppBuildForwardsRenovoBuild(): void
    {
        $root = dirname(__DIR__, 2);
        $compose = Yaml::parseFile($root . '/docker-compose.yml');
        $args = $compose['services']['app']['build']['args'] ?? [];

        self::assertSame('${RENOVO_BUILD:-}', $args['RENOVO_BUILD'] ?? null);
        self::assertMatchesRegularExpression(
            '/^ARG RENOVO_BUILD\b/m',
            (string) file_get_contents($root . '/docker/php/Dockerfile'),
        );
    }
}
