<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every reason an instance backup can fail or be refused with is shown on the
 * settings page as `backup.reason.<code>` (Phase 38). The key is built at run
 * time, so the catalogue check cannot see it; this does.
 */
final class BackupReasonsTranslatedTest extends TestCase
{
    public function testEveryReasonHasAString(): void
    {
        $root = dirname(__DIR__, 2);
        /** @var array<string, string> $catalogue */
        $catalogue = require $root . '/translations/en.php';

        $codes = ['unexpected', 'abandoned'];
        foreach ((array) glob($root . '/src/Service/Backup/*.php') as $file) {
            $source = (string) file_get_contents((string) $file);
            preg_match_all("/new BackupException\\(\\s*'([a-z_]+)'/", $source, $match);
            $codes = [...$codes, ...$match[1]];
        }
        $codes = array_values(array_unique($codes));

        self::assertGreaterThan(10, count($codes), 'The reasons were found.');

        // Phase 39: a destination's reasons, shown by Test connection and in
        // the rehearsal history.
        $reasons = [];
        foreach ((array) glob($root . '/src/Service/Backup/{,Destination/}*.php', GLOB_BRACE) as $file) {
            $source = (string) file_get_contents((string) $file);
            preg_match_all("/new DestinationException\\(\\s*[^,]+,\\s*'([a-z_]+)'/", $source, $match);
            $reasons = [...$reasons, ...$match[1]];
        }
        self::assertGreaterThan(8, count(array_unique($reasons)), 'The destination reasons were found.');
        foreach (array_unique(['blocked', 'transport', ...$reasons]) as $reason) {
            self::assertArrayHasKey('backup.destination_reason.' . $reason, $catalogue, $reason);
        }
        foreach ($codes as $code) {
            self::assertArrayHasKey('backup.reason.' . $code, $catalogue, $code);
        }
    }
}
