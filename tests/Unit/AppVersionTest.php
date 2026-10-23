<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\AppVersion;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The version the application reports is the one the VERSION file says, and a
 * build id is shown only when there is one.
 */
final class AppVersionTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'renovo-version-');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReadsTheVersionFile(): void
    {
        file_put_contents($this->file, "2.3.4\n");
        $version = new AppVersion($this->file);

        self::assertSame('2.3.4', $version->current());
        self::assertNull($version->build());
        self::assertSame('2.3.4', $version->label());
    }

    public function testReportsTheBuildWhenOneIsSet(): void
    {
        file_put_contents($this->file, '2.3.4');
        $version = new AppVersion($this->file, 'a1b2c3d');

        self::assertSame('a1b2c3d', $version->build());
        self::assertSame('2.3.4 (a1b2c3d)', $version->label());
    }

    public function testAnEmptyBuildIsNoBuild(): void
    {
        // The Dockerfile's default: `ARG RENOVO_BUILD=""` becomes an empty
        // environment variable, which must read as a release image.
        file_put_contents($this->file, '2.3.4');

        self::assertNull((new AppVersion($this->file, '  '))->build());
    }

    public function testFromEnvironmentReadsRenovoBuild(): void
    {
        $previous = getenv('RENOVO_BUILD');
        putenv('RENOVO_BUILD=f00ba47');

        try {
            $version = AppVersion::fromEnvironment(dirname(__DIR__, 2));
            self::assertSame('f00ba47', $version->build());
            self::assertSame(trim((string) file_get_contents(dirname(__DIR__, 2) . '/VERSION')), $version->current());
        } finally {
            putenv($previous === false ? 'RENOVO_BUILD' : 'RENOVO_BUILD=' . $previous);
        }
    }

    public function testAMissingFileIsAnError(): void
    {
        $this->expectException(RuntimeException::class);

        (new AppVersion($this->file . '.missing'))->current();
    }
}
