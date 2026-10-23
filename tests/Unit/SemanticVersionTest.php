<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\SemanticVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SemanticVersionTest extends TestCase
{
    public function testALeadingVIsDropped(): void
    {
        self::assertSame('1.2.0', SemanticVersion::parse('v1.2.0')?->toString());
        self::assertSame('1.2.0', SemanticVersion::parse(' 1.2.0 ')?->toString());
    }

    public function testBuildMetadataIsNotPartOfTheVersion(): void
    {
        $version = SemanticVersion::parse('1.2.0+build.5');

        self::assertSame('1.2.0', $version?->toString());
        self::assertSame(0, $version->compare(self::v('1.2.0')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'two parts' => ['1.2'];
        yield 'a word' => ['latest'];
        yield 'leading zero' => ['01.2.0'];
        yield 'four parts' => ['1.2.3.4'];
        yield 'markup' => ['1.2.0<script>'];
        yield 'too large' => ['1.2.12345678901'];
        yield 'empty pre-release' => ['1.2.0-'];
    }

    #[DataProvider('notVersions')]
    public function testRejects(string $value): void
    {
        self::assertNull(SemanticVersion::parse($value));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ordered(): iterable
    {
        yield 'minor as a number, not text' => ['1.9.0', '1.10.0'];
        yield 'patch' => ['1.0.0', '1.0.1'];
        yield 'major' => ['1.99.99', '2.0.0'];
        yield 'pre-release before release' => ['1.2.0-rc.1', '1.2.0'];
        yield 'numeric pre-release identifiers' => ['1.2.0-rc.2', '1.2.0-rc.10'];
        yield 'fewer identifiers first' => ['1.2.0-alpha', '1.2.0-alpha.1'];
        yield 'numeric before alphanumeric' => ['1.2.0-1', '1.2.0-alpha'];
        yield 'alphanumeric as text' => ['1.2.0-alpha', '1.2.0-beta'];
    }

    #[DataProvider('ordered')]
    public function testOrders(string $older, string $newer): void
    {
        self::assertTrue(self::v($newer)->isNewerThan(self::v($older)));
        self::assertFalse(self::v($older)->isNewerThan(self::v($newer)));
        self::assertLessThan(0, self::v($older)->compare(self::v($newer)));
    }

    public function testTheSameVersionIsNotNewer(): void
    {
        self::assertFalse(self::v('1.0.0')->isNewerThan(self::v('v1.0.0')));
        self::assertSame(0, self::v('1.0.0-rc.1')->compare(self::v('1.0.0-rc.1')));
    }

    private static function v(string $value): SemanticVersion
    {
        $version = SemanticVersion::parse($value);
        self::assertNotNull($version);

        return $version;
    }
}
