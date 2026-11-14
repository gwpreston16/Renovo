<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Persistence\MySqlPlatform;
use PDO;

/**
 * The connection `phinx.php` hands Phinx runs in UTC on both engines, so a
 * migration, a rollback or a seed reads and writes times as the application
 * does (K163 on PostgreSQL, K238 on MySQL), and on MySQL it keeps the
 * server's own sql_mode rather than the application's strict one.
 *
 * It asserts the session setting, not shifted data: CI's servers run in UTC,
 * so a data test would pass without the pin.
 */
final class PhinxConnectionTimeZoneTest extends DatabaseTestCase
{
    public function testPhinxsConnectionRunsInUtc(): void
    {
        if ($this->isMysql()) {
            self::assertSame('+00:00', $this->phinxConnection()->query('SELECT @@session.time_zone')->fetchColumn());

            return;
        }

        // A client zone of its own, so the pin is what puts the session in UTC.
        putenv('PGTZ=America/New_York');
        try {
            $pdo = $this->phinxConnection();
        } finally {
            putenv('PGTZ');
        }
        self::assertSame('UTC', $pdo->query('SHOW TIME ZONE')->fetchColumn());
    }

    public function testPhinxsMysqlConnectionKeepsTheServersSqlMode(): void
    {
        if (!$this->isMysql()) {
            self::markTestSkipped('MySQL only: PostgreSQL has no sql_mode.');
        }

        $pdo = $this->phinxConnection();

        self::assertSame(
            $pdo->query('SELECT @@global.sql_mode')->fetchColumn(),
            $pdo->query('SELECT @@session.sql_mode')->fetchColumn(),
        );
    }

    public function testTheApplicationSessionUsesTheSameUtcStatement(): void
    {
        self::assertContains(MySqlPlatform::UTC_SESSION, (new MySqlPlatform())->initialStatements());
    }

    /**
     * The PDO `phinx.php` builds for Phinx, from the same environment the
     * test database is configured by.
     */
    private function phinxConnection(): PDO
    {
        $config = require __DIR__ . '/../../phinx.php';
        $pdo = $config['environments']['testing']['connection'] ?? null;
        self::assertInstanceOf(PDO::class, $pdo, 'phinx.php hands Phinx its own connection.');

        return $pdo;
    }

    private function isMysql(): bool
    {
        return $this->db->platform()->name() === 'mysql';
    }
}
