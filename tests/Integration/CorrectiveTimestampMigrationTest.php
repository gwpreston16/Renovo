<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PDO;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Migration\AbstractMigration;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The Phase 62 migration (K110), run against a scratch table made the way a
 * server with `explicit_defaults_for_timestamp` off makes one: whatever the
 * test database itself does, the columns here say `ON UPDATE` out loud.
 */
final class CorrectiveTimestampMigrationTest extends DatabaseTestCase
{
    private const FILE = '20270901000001_stop_timestamps_updating_themselves.php';
    private const TABLE = 'ts_repair_scratch';

    /** @var list<string> */
    private array $executed = [];

    protected function tearDown(): void
    {
        if ($this->mayTruncate() && $this->db->platform()->name() === 'mysql') {
            $this->db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        }

        parent::tearDown();
    }

    public function testItRepairsAnAffectedSchema(): void
    {
        $this->makeAffectedTable();

        $this->migrate('up');

        self::assertSame([], $this->selfUpdatingColumns());
        $columns = $this->columns();
        self::assertSame('CURRENT_TIMESTAMP', $this->defaultOf($columns['a']));
        self::assertSame('timestamp(3)', strtolower($columns['c']['COLUMN_TYPE']));
        self::assertSame('CURRENT_TIMESTAMP(3)', $this->defaultOf($columns['c']));
        self::assertSame('when it happened', $columns['c']['COLUMN_COMMENT']);
        // The database under test may have other affected tables of its own;
        // what is asserted is that this one was altered once, not once a column.
        $alters = array_values(array_filter(
            $this->executed,
            static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE `' . self::TABLE . '`'),
        ));
        self::assertCount(1, $alters, 'one ALTER for the one table');
        self::assertStringContainsString('MODIFY `a`', $alters[0]);
        self::assertStringContainsString('MODIFY `b`', $alters[0]);
        self::assertStringContainsString('MODIFY `c`', $alters[0]);
    }

    public function testItKeepsNullability(): void
    {
        $this->makeAffectedTable();

        $this->migrate('up');

        $columns = $this->columns();
        self::assertSame('NO', $columns['a']['IS_NULLABLE']);
        self::assertSame('YES', $columns['b']['IS_NULLABLE']);
        self::assertNull($columns['b']['COLUMN_DEFAULT']);
        self::assertSame('NO', $columns['c']['IS_NULLABLE']);
    }

    public function testARepairedColumnIsNotRewrittenByAnUpdate(): void
    {
        $this->makeAffectedTable();
        $this->db->execute('INSERT INTO ' . self::TABLE . " (id, a) VALUES (1, '2026-01-02 03:04:05')");

        $this->migrate('up');
        $this->db->execute('UPDATE ' . self::TABLE . ' SET id = 2 WHERE id = 1');

        self::assertSame(
            '2026-01-02 03:04:05',
            (string) $this->db->fetchValue('SELECT a FROM ' . self::TABLE . ' WHERE id = 2'),
        );
    }

    public function testItIsIdempotent(): void
    {
        $this->makeAffectedTable();

        $this->migrate('up');
        $this->executed = [];
        $this->migrate('up');

        self::assertSame([], $this->executed);
        self::assertSame([], $this->selfUpdatingColumns());
    }

    public function testDownChangesNothing(): void
    {
        $this->makeAffectedTable();
        $this->migrate('up');
        $this->executed = [];

        $this->migrate('down');

        self::assertSame([], $this->executed);
        self::assertSame([], $this->selfUpdatingColumns());
    }

    public function testItIsANoOpOnPostgres(): void
    {
        $this->migrate('up', 'pgsql');

        self::assertSame([], $this->executed);
    }

    private function makeAffectedTable(): void
    {
        if ($this->db->platform()->name() !== 'mysql') {
            self::markTestSkipped('Only MySQL and MariaDB have an ON UPDATE clause on a column.');
        }

        $this->db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db->execute(
            'CREATE TABLE ' . self::TABLE . ' (id INT NOT NULL DEFAULT 0,'
            . ' a TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . ' b TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,'
            . ' c TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)'
            . " COMMENT 'when it happened')",
        );
    }

    /**
     * @return list<string>
     */
    private function selfUpdatingColumns(): array
    {
        return array_map(
            static fn (array $row): string => $row['column_name'],
            $this->db->fetchAll(
                'SELECT column_name AS column_name FROM information_schema.columns'
                . ' WHERE table_schema = DATABASE() AND table_name = :t AND LOWER(extra) LIKE :extra'
                . ' ORDER BY ordinal_position',
                ['t' => self::TABLE, 'extra' => '%on update%'],
            ),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function columns(): array
    {
        $columns = [];
        foreach (
            $this->db->fetchAll(
                'SELECT column_name AS cn, column_type AS COLUMN_TYPE, is_nullable AS IS_NULLABLE,'
                . ' column_default AS COLUMN_DEFAULT, column_comment AS COLUMN_COMMENT'
                . ' FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t',
                ['t' => self::TABLE],
            ) as $row
        ) {
            $columns[(string) $row['cn']] = $row;
        }

        return $columns;
    }

    /**
     * MariaDB reports a default as `current_timestamp()`, MySQL as
     * `CURRENT_TIMESTAMP`: the same thing.
     *
     * @param array<string, mixed> $column
     */
    private function defaultOf(array $column): string
    {
        return strtoupper(str_replace('()', '', (string) $column['COLUMN_DEFAULT']));
    }

    private function migrate(string $direction, ?string $adapterType = null): void
    {
        require_once __DIR__ . '/../../migrations/' . self::FILE;

        $pdo = $this->db->connection();
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('getAdapterType')->willReturn($adapterType ?? $this->db->platform()->name());
        $adapter->method('fetchAll')->willReturnCallback(
            static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC),
        );
        $adapter->method('execute')->willReturnCallback(function (string $sql) use ($pdo): int {
            $this->executed[] = $sql;

            return (int) $pdo->exec($sql);
        });

        $migration = new \StopTimestampsUpdatingThemselves('testing', 20270901000001);
        $migration->setAdapter($adapter);
        $migration->setOutput(new BufferedOutput());
        $migration->{$direction}();
    }
}
