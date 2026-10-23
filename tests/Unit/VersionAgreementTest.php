<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `VERSION`, `package.json` and the newest released `CHANGELOG.md` heading
 * name the same release.
 *
 * `VERSION` is what the running application reports; the other two are what a
 * reader of the repository and of the release notes sees. A release that
 * moved one and not the others would tell an operator they are up to date when
 * they are not, or the reverse. `/release` writes all three; this is what
 * notices when it didn't.
 */
final class VersionAgreementTest extends TestCase
{
    public function testTheVersionFilePackageJsonAndChangelogAgree(): void
    {
        $root = dirname(__DIR__, 2);

        $version = trim((string) file_get_contents($root . '/VERSION'));
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version, 'VERSION is not X.Y.Z.');

        $package = json_decode((string) file_get_contents($root . '/package.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        self::assertSame($version, $package['version'] ?? null, 'package.json and VERSION disagree.');

        // The newest heading that is a release, not [Unreleased].
        preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', (string) file_get_contents($root . '/CHANGELOG.md'), $match);
        self::assertSame($version, $match[1] ?? null, 'The newest released CHANGELOG.md heading and VERSION disagree.');
    }
}
