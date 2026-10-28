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

    /**
     * Docker fills an empty named volume from the image as each container that
     * mounts it is created. Two created at once both copy, and the second fails
     * on a directory the first just made (`mkdir .../backups: file exists`), so
     * one of the services sharing a volume fills it and the rest wait for it.
     */
    public function testServicesSharingAVolumeAreNotCreatedTogether(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 2) . '/docker-compose.yml');
        $services = $compose['services'];

        $mounts = [];
        foreach ($services as $name => $service) {
            foreach ($service['volumes'] ?? [] as $volume) {
                $source = explode(':', (string) $volume)[0];
                if (array_key_exists($source, $compose['volumes'] ?? [])) {
                    $mounts[$source][] = $name;
                }
            }
        }
        self::assertSame(['app', 'scheduler'], $mounts['appvar'] ?? null, 'the test sees the shared var/ volume');

        foreach ($mounts as $volume => $names) {
            $fillers = array_filter($names, fn (string $first): bool => array_all(
                $names,
                fn (string $name): bool => $name === $first || $this->waitsFor($services, $name, $first),
            ));
            self::assertNotEmpty(
                $fillers,
                sprintf('%s mount %s, and no one of them is waited for by the rest', implode(', ', $names), $volume),
            );
        }
    }

    /**
     * Whether $service depends on $other, directly or through another service.
     *
     * @param array<string, array<string, mixed>> $services
     */
    private function waitsFor(array $services, string $service, string $other): bool
    {
        $seen = [];
        $queue = [$service];
        while ($queue !== []) {
            $dependsOn = $services[array_shift($queue)]['depends_on'] ?? [];
            foreach (array_is_list($dependsOn) ? $dependsOn : array_keys($dependsOn) as $dependency) {
                if ($dependency === $other) {
                    return true;
                }
                if (!isset($seen[$dependency])) {
                    $seen[$dependency] = true;
                    $queue[] = $dependency;
                }
            }
        }

        return false;
    }
}
