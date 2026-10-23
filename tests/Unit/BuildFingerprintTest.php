<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\BuildFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * K4: compiled caches are named for the code that compiled them, so an
 * upgrade never runs on the previous release's container.
 */
final class BuildFingerprintTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/renovo-build-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/vendor/composer', 0777, true);
        file_put_contents($this->root . '/VERSION', "1.8.0\n");
        file_put_contents($this->root . '/vendor/composer/autoload_static.php', '<?php');
        touch($this->root . '/vendor/composer/autoload_static.php', 1_700_000_000);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheSameCodeGivesTheSameName(): void
    {
        self::assertSame(BuildFingerprint::of($this->root), BuildFingerprint::of($this->root));
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', BuildFingerprint::of($this->root));
    }

    public function testANewReleaseOrARegeneratedAutoloaderGivesANewName(): void
    {
        $before = BuildFingerprint::of($this->root);

        file_put_contents($this->root . '/VERSION', "1.9.0\n");
        $afterRelease = BuildFingerprint::of($this->root);
        self::assertNotSame($before, $afterRelease);

        touch($this->root . '/vendor/composer/autoload_static.php', 1_700_000_100);
        clearstatcache();
        self::assertNotSame($afterRelease, BuildFingerprint::of($this->root));
    }

    public function testPruningRemovesEveryOtherBuildsCaches(): void
    {
        $cache = $this->root . '/var/cache';
        mkdir($cache . '/old-build/container', 0777, true);
        file_put_contents($cache . '/old-build/container/CompiledContainer.php', '<?php');
        mkdir($cache . '/twig/ab', 0777, true);
        mkdir($cache . '/current', 0777, true);

        BuildFingerprint::pruneSiblings($cache . '/current');

        self::assertDirectoryDoesNotExist($cache . '/old-build');
        self::assertDirectoryDoesNotExist($cache . '/twig');
        self::assertDirectoryExists($cache . '/current');
    }
}
