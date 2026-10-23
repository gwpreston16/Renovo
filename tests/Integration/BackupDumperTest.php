<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Persistence\Database;
use App\Persistence\PlatformFactory;
use App\Repository\BackupDumper;
use InvalidArgumentException;

/**
 * The instance backup's view of the schema (Phase 38).
 *
 * The coverage test is the one that matters most over time: it fails the day
 * a migration adds a table and nobody decides whether instance backups carry
 * it.
 */
final class BackupDumperTest extends DatabaseTestCase
{
    private BackupDumper $dumper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dumper = new BackupDumper($this->db);
    }

    public function testEveryTableIsEitherBackedUpOrExcluded(): void
    {
        $existing = $this->dumper->existingTables();
        $chosen = [...BackupDumper::TABLES, ...BackupDumper::EXCLUDED];

        self::assertSame([], array_values(array_diff($existing, $chosen)), 'Tables nobody chose for instance backups.');
        self::assertSame([], array_values(array_diff($chosen, $existing)), 'Listed tables that do not exist.');
        self::assertSame([], array_values(array_intersect(BackupDumper::TABLES, BackupDumper::EXCLUDED)));
    }

    public function testTablesAreListedParentsBeforeChildren(): void
    {
        $position = array_flip(BackupDumper::TABLES);

        foreach ($this->dumper->foreignKeys() as $edge) {
            if (!isset($position[$edge['child']]) || $edge['child'] === $edge['parent']) {
                continue;
            }

            self::assertArrayHasKey(
                $edge['parent'],
                $position,
                sprintf('%s is backed up but references %s, which is not.', $edge['child'], $edge['parent']),
            );
            self::assertLessThan(
                $position[$edge['child']],
                $position[$edge['parent']],
                sprintf('%s must come before %s, which references it.', $edge['parent'], $edge['child']),
            );
        }
    }

    public function testEveryBackedUpTableHasAPrimaryKey(): void
    {
        foreach (BackupDumper::TABLES as $table) {
            self::assertNotSame([], $this->dumper->primaryKey($table), $table);
        }
    }

    public function testRowsComeBackInKeyOrderWithBooleansAsBooleans(): void
    {
        foreach ([3, 1, 2] as $n) {
            $this->db->execute(
                'INSERT INTO users (id, email, display_name, password_hash, is_instance_admin, created_at, updated_at)'
                . " VALUES (:id, :e, :n, 'x', :a, '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
                ['id' => $n, 'e' => "u{$n}@example.test", 'n' => "U{$n}", 'a' => $n === 2],
            );
        }

        $rows = iterator_to_array($this->dumper->rows('users'), false);

        self::assertSame([1, 2, 3], array_map(static fn (array $r): int => (int) $r['id'], $rows));
        self::assertSame([false, true, false], array_column($rows, 'is_instance_admin'));
    }

    public function testRowsPageThroughACompositeKey(): void
    {
        $this->db->execute(
            'INSERT INTO users (id, email, display_name, password_hash, is_instance_admin, created_at, updated_at)'
            . " VALUES (1, 'a@example.test', 'A', 'x', :f, '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
            ['f' => false],
        );
        $cards = 0;
        foreach (['dashboard', 'household'] as $view) {
            for ($i = 0; $i < 300; $i++) {
                $this->db->execute(
                    'INSERT INTO dashboard_cards (user_id, card_key, position, is_visible, view)'
                    . ' VALUES (1, :k, :p, :v, :w)',
                    ['k' => sprintf('card-%03d', $i), 'p' => $i, 'v' => true, 'w' => $view],
                );
                $cards++;
            }
        }

        $rows = iterator_to_array($this->dumper->rows('dashboard_cards'), false);

        self::assertCount($cards, $rows);
        $keys = array_map(
            static fn (array $r): string => implode('|', array_map('strval', array_intersect_key(
                $r,
                array_flip(['user_id', 'card_key', 'view']),
            ))),
            $rows,
        );
        self::assertCount($cards, array_unique($keys), 'No row is read twice across a page boundary.');
    }

    public function testInsertAcceptsOnlyTheTablesColumns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->dumper->insert('tags', ['id' => 1, 'name) VALUES (1); --' => 'x']);
    }

    public function testUnknownTablesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->dumper->count('users; DROP TABLE users');
    }

    public function testResetIdentityContinuesPastTheHighestId(): void
    {
        $this->dumper->insert('users', [
            'id' => 41,
            'email' => 'restored@example.test',
            'display_name' => 'Restored',
            'password_hash' => 'x',
            'is_instance_admin' => true,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->dumper->resetIdentity('users');

        $next = $this->db->insert('users', [
            'email' => 'new@example.test',
            'display_name' => 'New',
            'password_hash' => 'x',
            'is_instance_admin' => false,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        self::assertGreaterThan(41, $next);
    }

    public function testASnapshotDoesNotSeeARowWrittenWhileItIsOpen(): void
    {
        $this->db->execute(
            'INSERT INTO users (id, email, display_name, password_hash, is_instance_admin, created_at, updated_at)'
            . " VALUES (1, 'a@example.test', 'A', 'x', :f, '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
            ['f' => false],
        );
        $other = $this->secondConnection();

        $seen = $this->dumper->snapshot(function () use ($other): array {
            $before = $this->dumper->count('tags');
            // Another connection commits a household and a tag mid-snapshot.
            $other->execute(
                'INSERT INTO households (name, created_by_user_id, created_at, updated_at)'
                . " VALUES ('Mid', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
            );
            $household = (int) $other->fetchValue('SELECT MAX(id) FROM households');
            $other->execute(
                'INSERT INTO tags (household_id, name, created_at, updated_at)'
                . " VALUES (:h, 'mid', '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
                ['h' => $household],
            );

            return [
                $before,
                $this->dumper->count('tags'),
                $this->dumper->count('households'),
                $this->dumper->count('users'),
            ];
        });

        self::assertSame([0, 0, 0, 1], $seen);
        self::assertSame(1, $this->dumper->count('tags'), 'Once the snapshot ends the row is there.');
    }

    public function testASnapshotThatThrowsRollsBack(): void
    {
        try {
            $this->dumper->snapshot(static function (): void {
                throw new \RuntimeException('mid-dump');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('mid-dump', $exception->getMessage(), 'The exception reaches the caller.');
        }

        self::assertFalse($this->db->connection()->inTransaction());
        // And the connection writes again, which a READ ONLY transaction
        // left open would refuse.
        $this->db->execute(
            'INSERT INTO users (email, display_name, password_hash, is_instance_admin, created_at, updated_at)'
            . " VALUES ('after@example.test', 'After', 'x', :f, '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
            ['f' => false],
        );
        self::assertSame(1, $this->dumper->count('users'));
    }

    public function testASnapshotCannotJoinAnotherTransaction(): void
    {
        $this->expectException(\LogicException::class);

        $this->db->transactional(fn () => $this->dumper->snapshot(static fn () => null));
    }

    private function secondConnection(): Database
    {
        $driver = $this->env('DB_DRIVER', 'pgsql');

        return new Database([
            'driver' => $driver,
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $driver === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ], PlatformFactory::create($driver));
    }
}
