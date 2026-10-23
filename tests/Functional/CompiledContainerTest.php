<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Security\KeyRing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * K4: with `APP_DEBUG=false` the container is compiled to disk and reused, and
 * it used to carry every setting as a literal — so a new `SECRETS_KEY`, a
 * rotated `SESSION_KEY` or a changed `DB_PASSWORD` did nothing until someone
 * deleted the cache. Settings are now read when the container starts.
 */
final class CompiledContainerTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testACompiledContainerReadsSettingsFromTheEnvironmentEachTime(): void
    {
        $_ENV['APP_DEBUG'] = 'false';
        $_ENV['SECRETS_KEY'] = 'first-key-' . bin2hex(random_bytes(8));

        $first = $this->container();
        $settings = $first->get('settings');
        self::assertIsArray($settings);
        $compiled = $settings['paths']['cache'] . '/container/CompiledContainer.php';
        $existed = is_file($compiled);

        try {
            self::assertFileExists($compiled, 'With debug off the container is compiled.');
            self::assertSame($_ENV['SECRETS_KEY'], $first->get(KeyRing::class)->current());
            self::assertStringNotContainsString(
                $_ENV['SECRETS_KEY'],
                (string) file_get_contents($compiled),
                'No setting, and so no secret, is written into the compiled container.',
            );

            $_ENV['SECRETS_KEY'] = 'second-key-' . bin2hex(random_bytes(8));

            self::assertSame($_ENV['SECRETS_KEY'], $this->container()->get(KeyRing::class)->current());
        } finally {
            if (!$existed) {
                exec('rm -rf ' . escapeshellarg(dirname($compiled)));
            }
        }
    }

    private function container(): ContainerInterface
    {
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $container = $bootstrap(false)->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
