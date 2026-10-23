<?php

declare(strict_types=1);

namespace App\Repository;

use App\Persistence\Database;
use Closure;
use InvalidArgumentException;

/**
 * Every row of every table, read and written for an instance backup (Phase 38).
 *
 * The one place an instance backup touches SQL, so the rule that persistence
 * lives in the repository layer holds for it too. It reads with portable SQL
 * rather than shelling out to `pg_dump` or `mysqldump`: a backup taken on
 * PostgreSQL restores into MySQL and the other way round, and the image needs
 * no client binaries.
 *
 * Values are written in one canonical form whichever engine they came from.
 * The only difference that matters is booleans — PostgreSQL returns `bool`,
 * MySQL's `tinyint(1)` returns `int` — so boolean columns are found in
 * `information_schema` and turned into `bool` on the way out. On the way back
 * in a `bool` is what `Database` binds as each engine's own boolean; an `int`
 * bound to a PostgreSQL boolean column is an error.
 *
 * Not final: a test stands in a dumper that checks every read happens inside
 * the snapshot.
 *
 * It is not scoped, by design: an instance backup is the whole instance. Only
 * `InstanceBackupService` and the restore use it, and only an instance
 * administrator or the operator's shell reaches them.
 */
class BackupDumper
{
    /**
     * Every table an instance backup carries, parents before children: the
     * order a restore inserts them in. `phinxlog` is carried so a restore can
     * check the schema it is going into, and is never inserted.
     */
    public const TABLES = [
        'users',
        'households',
        'household_memberships',
        'instance_settings',
        'trusted_hosts',
        'categories',
        'tags',
        'payment_methods',
        'exchange_rates',
        'subscriptions',
        'subscription_price_history',
        'subscription_charges',
        'subscription_splits',
        'subscription_tags',
        'budgets',
        'budget_alert_state',
        'notification_preferences',
        'notification_channels',
        'notification_routes',
        'notification_log',
        'user_totp',
        'recovery_codes',
        'webauthn_credentials',
        'attachments',
        'api_tokens',
        'auth_tokens',
        'dashboard_cards',
        'saved_views',
        'audit_log',
        self::MIGRATIONS_TABLE,
    ];

    /**
     * Tables an instance backup leaves out, each for a reason:
     *
     *  - `sessions`, `auth_attempts`, `http_metrics` — ephemeral; a restored
     *    instance starts with nobody signed in, no throttle and fresh counts;
     *  - `logo_cache` — rows describing files in `var/logo-cache`, a download
     *    cache that is not backed up and refills itself;
     *  - `backup_runs` — the history of backups on *this* server. Restored,
     *    it would list files the new server does not have.
     *
     * A migration that adds a table must put it in one list or the other;
     * `BackupDumperTest::testEveryTableIsEitherBackedUpOrExcluded` fails until
     * it does.
     */
    public const EXCLUDED = [
        'sessions',
        'auth_attempts',
        'http_metrics',
        'logo_cache',
        'backup_runs',
    ];

    public const MIGRATIONS_TABLE = 'phinxlog';

    /** Rows read per query, so no table is ever held in memory whole. */
    private const PAGE = 500;

    /** @var array<string, list<string>> */
    private array $columns = [];

    /** @var array<string, list<string>> */
    private array $primaryKeys = [];

    /** @var array<string, array<string, true>> */
    private array $booleans = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function engine(): string
    {
        return $this->db->platform()->name();
    }

    /**
     * Run `$work` inside one consistent, read-only snapshot.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function snapshot(Closure $work): mixed
    {
        return $this->db->snapshot(static fn (): mixed => $work());
    }

    /**
     * Run `$work` inside one transaction — the whole restore, so a failure
     * part-way leaves the database as empty as it was.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function transactional(Closure $work): mixed
    {
        return $this->db->transactional(static fn (): mixed => $work());
    }

    /**
     * The base tables that exist in this database.
     *
     * @return list<string>
     */
    public function existingTables(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT table_name AS name FROM information_schema.tables'
            . ' WHERE table_schema = ' . $this->db->platform()->currentSchema()
            . " AND table_type = 'BASE TABLE' ORDER BY table_name",
        );

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * Every foreign key as a child → parent edge.
     *
     * @return list<array{child: string, parent: string}>
     */
    public function foreignKeys(): array
    {
        $edges = [];
        foreach ($this->db->fetchAll($this->db->platform()->foreignKeysSql()) as $row) {
            $edges[] = ['child' => (string) $row['child'], 'parent' => (string) $row['parent']];
        }

        return $edges;
    }

    /**
     * @return list<string>
     */
    public function columns(string $table): array
    {
        $table = $this->known($table);

        return $this->columns[$table] ??= array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->db->fetchAll(
                'SELECT column_name AS name FROM information_schema.columns'
                . ' WHERE table_schema = ' . $this->db->platform()->currentSchema()
                . ' AND table_name = :t ORDER BY ordinal_position',
                ['t' => $table],
            ),
        );
    }

    public function count(string $table): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . $this->quote($this->known($table)));
    }

    /**
     * Every row of a table, in primary-key order, a page at a time.
     *
     * Keyset paging rather than OFFSET: each page starts after the last key
     * the previous one returned, through the primary key's index, so a large
     * table costs the same per page at the end as at the start. Inside a snapshot every page sees the same
     * rows.
     *
     * @return iterable<array<string, mixed>>
     */
    public function rows(string $table): iterable
    {
        $table = $this->known($table);
        $key = $this->primaryKey($table);
        $booleans = $this->booleanColumns($table);
        $quotedKey = implode(', ', array_map($this->quote(...), $key));
        $after = null;

        do {
            $sql = 'SELECT * FROM ' . $this->quote($table);
            $params = [];
            if ($after !== null) {
                $sql .= ' WHERE ' . $this->after($key, $after, $params);
            }
            $sql .= ' ORDER BY ' . $quotedKey . ' LIMIT ' . self::PAGE;

            $page = $this->db->fetchAll($sql, $params);

            foreach ($page as $row) {
                foreach ($booleans as $column => $_) {
                    if ($row[$column] !== null) {
                        $row[$column] = $this->db->platform()->toBoolean($row[$column]);
                    }
                }

                yield $row;
                $after = $row;
            }
        } while (count($page) === self::PAGE);
    }

    /**
     * "Comes after this key" as `a > :x OR (a = :x AND b > :y) …` rather than
     * the row comparison `(a, b) > (:x, :y)`: the two mean the same, but
     * MySQL does not use the primary key for a row comparison, and each page
     * would scan the table from the start. Every placeholder is its own, as
     * MySQL's native prepares need.
     *
     * @param list<string> $key
     * @param array<string, mixed> $after
     * @param array<string, mixed> $params
     */
    private function after(array $key, array $after, array &$params): string
    {
        $terms = [];
        foreach ($key as $i => $column) {
            $parts = [];
            foreach (array_slice($key, 0, $i) as $j => $equal) {
                $name = sprintf('e%d_%d', $i, $j);
                $parts[] = $this->quote($equal) . ' = :' . $name;
                $params[$name] = $after[$equal];
            }
            $name = 'g' . $i;
            $parts[] = $this->quote($column) . ' > :' . $name;
            $params[$name] = $after[$column];
            $terms[] = '(' . implode(' AND ', $parts) . ')';
        }

        return '(' . implode(' OR ', $terms) . ')';
    }

    /**
     * Insert one row as it was dumped. Only columns the table has are
     * accepted — the names come out of an archive, and an archive is
     * untrusted however it was made.
     *
     * @param array<string, mixed> $row
     */
    public function insert(string $table, array $row): void
    {
        $table = $this->known($table);
        $columns = array_keys($row);
        $unknown = array_diff($columns, $this->columns($table));

        if ($unknown !== [] || $columns === []) {
            throw new InvalidArgumentException(sprintf(
                'A row for "%s" names columns the table does not have: %s.',
                $table,
                implode(', ', $unknown),
            ));
        }

        $params = [];
        $placeholders = [];
        foreach ($columns as $i => $column) {
            $params['v' . $i] = $row[$column];
            $placeholders[] = ':v' . $i;
        }

        $this->db->execute(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quote($table),
            implode(', ', array_map($this->quote(...), $columns)),
            implode(', ', $placeholders),
        ), $params);
    }

    /**
     * Move a table's id sequence past the rows a restore inserted, so the
     * next row written gets the next id rather than a duplicate of the first.
     */
    public function resetIdentity(string $table): void
    {
        $table = $this->known($table);

        if (!in_array('id', $this->columns($table), true) || $this->primaryKey($table) !== ['id']) {
            return;
        }

        $statement = $this->db->platform()->resetIdentityStatement($table, 'id');
        if ($statement !== null) {
            $this->db->fetchValue($statement);
        }
    }

    /**
     * @return list<string>
     */
    public function primaryKey(string $table): array
    {
        $table = $this->known($table);

        return $this->primaryKeys[$table] ??= array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->db->fetchAll(
                'SELECT kcu.column_name AS name FROM information_schema.table_constraints tc'
                . ' JOIN information_schema.key_column_usage kcu'
                . ' ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema'
                . ' AND kcu.table_name = tc.table_name'
                . " WHERE tc.constraint_type = 'PRIMARY KEY'"
                . ' AND tc.table_schema = ' . $this->db->platform()->currentSchema()
                . ' AND tc.table_name = :t ORDER BY kcu.ordinal_position',
                ['t' => $table],
            ),
        );
    }

    /**
     * @return array<string, true>
     */
    private function booleanColumns(string $table): array
    {
        if (!isset($this->booleans[$table])) {
            $this->booleans[$table] = [];
            $rows = $this->db->fetchAll(
                'SELECT column_name AS name FROM information_schema.columns'
                . ' WHERE table_schema = ' . $this->db->platform()->currentSchema()
                . ' AND table_name = :t AND ' . $this->db->platform()->booleanColumnCondition(),
                ['t' => $table],
            );
            foreach ($rows as $row) {
                $this->booleans[$table][(string) $row['name']] = true;
            }
        }

        return $this->booleans[$table];
    }

    /**
     * Table names are interpolated into SQL, so only the ones this class
     * names are accepted.
     */
    private function known(string $table): string
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a table an instance backup carries.', $table));
        }

        return $table;
    }

    private function quote(string $identifier): string
    {
        return $this->db->platform()->quoteIdentifier($identifier);
    }
}
