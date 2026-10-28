<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use Phinx\Db\Adapter\AdapterInterface;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The Phase 65 migration (K32): passkey ids and handles compared byte for
 * byte on MySQL and MariaDB, and put back as they were by `down()`.
 *
 * It runs against the test database's own tables, so every test ends with
 * `up()` applied: the state `phinx migrate` leaves it in.
 */
final class WebauthnCollationMigrationTest extends DatabaseTestCase
{
    private const FILE = '20271001000001_compare_passkey_ids_exactly.php';

    /** @var list<string> */
    private array $executed = [];

    public function testUpAndDownRoundTrip(): void
    {
        $this->requireMySql();
        $before = $this->columns();

        try {
            $this->migrate('down');
            $rolledBack = $this->columns();
            self::assertSame($this->tableCollation('webauthn_credentials'), $rolledBack['credential_id']['collation']);
            self::assertSame($this->tableCollation('users'), $rolledBack['webauthn_handle']['collation']);

            $this->migrate('up');
        } finally {
            // Whatever failed above, leave the schema as the suite expects it.
            $this->migrate('up');
        }

        $after = $this->columns();
        self::assertSame($before, $after);
        self::assertSame('ascii_bin', $after['credential_id']['collation']);
        self::assertSame('ascii_bin', $after['webauthn_handle']['collation']);
        self::assertSame('varchar(255)', $after['credential_id']['type']);
        self::assertSame('NO', $after['credential_id']['nullable']);
        self::assertSame('varchar(64)', $after['webauthn_handle']['type']);
        self::assertSame('YES', $after['webauthn_handle']['nullable']);
        self::assertSame(['uq_users_webauthn_handle', 'uq_webauthn_credential_id'], $this->uniqueIndexes());
    }

    public function testItKeepsWhatIsStored(): void
    {
        $this->requireMySql();
        $this->db->execute(
            "INSERT INTO users (email, display_name, password_hash, is_instance_admin, theme, webauthn_handle,"
            . " created_at, updated_at) VALUES ('keep@example.test', 'Keep', 'hash', 0, 'system', 'Kept-Handle_9',"
            . " '2026-10-28 09:00:00', '2026-10-28 09:00:00')",
        );

        try {
            $this->migrate('down');
        } finally {
            $this->migrate('up');
        }

        self::assertSame(
            'Kept-Handle_9',
            $this->db->fetchValue("SELECT webauthn_handle FROM users WHERE email = 'keep@example.test'"),
        );
    }

    public function testItIsANoOpOnPostgres(): void
    {
        $this->migrate('up', 'pgsql');
        $this->migrate('down', 'pgsql');

        self::assertSame([], $this->executed);
    }

    private function requireMySql(): void
    {
        if ($this->db->platform()->name() !== 'mysql') {
            self::markTestSkipped('PostgreSQL compares text exactly already; the migration is MySQL/MariaDB only.');
        }
    }

    /**
     * @return array<string, array{type: string, nullable: string, collation: string}>
     */
    private function columns(): array
    {
        $columns = [];
        foreach (
            $this->db->fetchAll(
                'SELECT column_name AS cn, column_type AS ct, is_nullable AS nl, collation_name AS co'
                . ' FROM information_schema.columns WHERE table_schema = DATABASE()'
                . " AND ((table_name = 'webauthn_credentials' AND column_name = 'credential_id')"
                . " OR (table_name = 'users' AND column_name = 'webauthn_handle'))",
            ) as $row
        ) {
            $columns[(string) $row['cn']] = [
                'type' => strtolower((string) $row['ct']),
                'nullable' => (string) $row['nl'],
                'collation' => (string) $row['co'],
            ];
        }
        ksort($columns);

        return $columns;
    }

    private function tableCollation(string $table): string
    {
        return (string) $this->db->fetchValue(
            'SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => $table],
        );
    }

    /**
     * @return list<string>
     */
    private function uniqueIndexes(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['n'],
            $this->db->fetchAll(
                'SELECT DISTINCT index_name AS n FROM information_schema.statistics'
                . " WHERE table_schema = DATABASE() AND non_unique = 0"
                . " AND index_name IN ('uq_webauthn_credential_id', 'uq_users_webauthn_handle')"
                . ' ORDER BY index_name',
            ),
        );
    }

    private function migrate(string $direction, ?string $adapterType = null): void
    {
        require_once __DIR__ . '/../../migrations/' . self::FILE;

        $pdo = $this->db->connection();
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('getAdapterType')->willReturn($adapterType ?? $this->db->platform()->name());
        $adapter->method('fetchRow')->willReturnCallback(
            static fn (string $sql): array|false => $pdo->query($sql)->fetch(PDO::FETCH_ASSOC),
        );
        $adapter->method('execute')->willReturnCallback(function (string $sql) use ($pdo): int {
            $this->executed[] = $sql;

            return (int) $pdo->exec($sql);
        });

        $migration = new \ComparePasskeyIdsExactly('testing', 20271001000001);
        $migration->setAdapter($adapter);
        $migration->setOutput(new BufferedOutput());
        $migration->{$direction}();
    }
}
