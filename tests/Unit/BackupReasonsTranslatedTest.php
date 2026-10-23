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
        foreach ($codes as $code) {
            self::assertArrayHasKey('backup.reason.' . $code, $catalogue, $code);
        }
    }
}
