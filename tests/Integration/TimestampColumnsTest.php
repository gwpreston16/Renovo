<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * No timestamp column rewrites itself (Phase 62, K110).
 *
 * On a MySQL or MariaDB server with `explicit_defaults_for_timestamp` off, the
 * first `timestamp NOT NULL` column of a table is made `ON UPDATE
 * CURRENT_TIMESTAMP`. The CI leg that runs MySQL that way is what gives this
 * test something to catch; on every other server it asserts the same thing and
 * finds nothing to complain about.
 */
final class TimestampColumnsTest extends DatabaseTestCase
{
    public function testNoTimestampColumnUpdatesItself(): void
    {
        if ($this->db->platform()->name() !== 'mysql') {
            self::markTestSkipped('Only MySQL and MariaDB have an ON UPDATE clause on a column.');
        }

        $rows = $this->db->fetchAll(
            'SELECT table_name AS t, column_name AS c FROM information_schema.columns'
            . ' WHERE table_schema = DATABASE() AND data_type = :type AND LOWER(extra) LIKE :extra'
            . ' ORDER BY table_name, column_name',
            ['type' => 'timestamp', 'extra' => '%on update%'],
        );

        self::assertSame(
            [],
            array_map(static fn (array $row): string => $row['t'] . '.' . $row['c'], $rows),
            'These columns are overwritten with the time of any later UPDATE of their row.',
        );
    }
}
