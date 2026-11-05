<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A new migration's version must sort after the highest one already there
 * (K187). Migration versions run ahead of the calendar, so `phinx create`
 * today makes one below the newest: it is applied out of order, and
 * `phinx rollback` then undoes the newest migration instead of the new one.
 */
final class MigrationOrderTest extends TestCase
{
    /**
     * What is wrong with a list of migration files, given in the order they
     * were added: a version that is not a 14-digit timestamp, a version used
     * twice, or one lower than a version added before it.
     *
     * @param list<string> $files
     * @return list<string>
     */
    private static function problems(array $files): array
    {
        $problems = [];
        $seen = [];
        $highest = '';

        foreach ($files as $file) {
            if (preg_match('/^(\d{14})_[a-z0-9_]+\.php$/', $file, $match) !== 1) {
                $problems[] = $file . ' is not named <14-digit version>_<snake_case_name>.php';
                continue;
            }

            $version = $match[1];
            if (isset($seen[$version])) {
                $problems[] = $file . ' reuses the version of ' . $seen[$version];
            }
            if ($highest !== '' && $version < $highest) {
                $problems[] = $file . ' sorts before ' . $highest . ', which was added earlier';
            }

            $seen[$version] ??= $file;
            $highest = max($highest, $version);
        }

        return $problems;
    }

    public function testAMigrationBelowTheNewestIsReported(): void
    {
        $problems = self::problems([
            '20271001000001_compare_passkey_ids_exactly.php',
            '20261031000001_added_with_phinx_create_today.php',
        ]);

        self::assertCount(1, $problems);
        self::assertStringContainsString('20261031000001_added_with_phinx_create_today.php sorts before', $problems[0]);
    }

    public function testAReusedVersionIsReported(): void
    {
        $problems = self::problems([
            '20271001000001_first.php',
            '20271001000001_second.php',
        ]);

        self::assertSame(['20271001000001_second.php reuses the version of 20271001000001_first.php'], $problems);
    }

    public function testMigrationsAddedInIncreasingOrderPass(): void
    {
        self::assertSame([], self::problems([
            '20260101000001_create_users_table.php',
            '20260101000002_create_households_table.php',
            '20271001000001_compare_passkey_ids_exactly.php',
        ]));
    }

    public function testEveryMigrationOnDiskHasAUniqueWellFormedVersion(): void
    {
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.php') ?: []);
        sort($files);

        self::assertNotEmpty($files);
        self::assertSame([], self::problems($files));
    }

    /**
     * Every migration was added after the ones below it. The files on disk
     * always sort, so the order that matters is the one history records: a
     * migration made with `phinx create` today and added last, with an older
     * date than the newest, shows up here and nowhere else.
     */
    public function testMigrationsWereAddedInVersionOrder(): void
    {
        $root = dirname(__DIR__, 2);
        $output = [];
        $status = 1;
        exec(
            'git -C ' . escapeshellarg($root)
            . ' log --reverse --diff-filter=A --format= --name-only -- migrations 2>&1',
            $output,
            $status,
        );
        if ($status !== 0) {
            self::markTestSkipped('No git history to read the order migrations were added in.');
        }

        $added = [];
        foreach ($output as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'migrations/') && str_ends_with($line, '.php')) {
                $added[] = basename($line);
            }
        }
        if ($added === []) {
            self::markTestSkipped('The history holds no migrations.');
        }

        self::assertSame([], self::problems($added));
    }
}
