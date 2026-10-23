<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Persistence\Database;
use App\Persistence\PlatformFactory;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a real database.
 *
 * There is no in-memory fallback on purpose: SQLite would accept SQL that
 * PostgreSQL and MySQL reject and would tell us nothing about whether the
 * application actually runs on the engines it ships against. If no database is
 * reachable the tests skip locally and fail in CI, where one is always
 * provided.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Database $db;

    /**
     * Whether `truncateAll()` is allowed to run at all.
     *
     * Set only once `assertSafeToDestroy()` has passed, and checked by every
     * caller rather than by `setUp()` alone. `markTestSkipped()` throws, but
     * PHPUnit still runs `tearDown()` afterwards — so a guard that lived only
     * in `setUp()` refused to empty a development database on the way in and
     * then emptied it on the way out, which is worse than having no guard,
     * because the message says it was refused.
     */
    private bool $mayTruncate = false;

    protected function setUp(): void
    {
        $config = [
            'driver' => $this->env('DB_DRIVER', 'pgsql'),
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ];

        $this->db = new Database($config, PlatformFactory::create($config['driver']));

        try {
            $this->db->connection();
        } catch (PDOException $exception) {
            if ($this->env('CI', '') !== '') {
                self::fail('CI must provide a database: ' . $exception->getMessage());
            }

            self::markTestSkipped(
                'No database reachable. Start one with `docker compose up database` and apply migrations. '
                . 'Details: ' . $exception->getMessage(),
            );
        }

        // Before anything is written, and before `$this->db` can be used to
        // empty anything: this throws past the two lines below.
        $this->assertSafeToDestroy($config['name']);

        $this->mayTruncate = true;

        $this->assertSchemaPresent();
        $this->truncateAll();
    }

    /**
     * Refuse to run against a database that does not look like a test one.
     *
     * These tests empty every table on every test. Pointed at a development
     * database — the obvious mistake, since `.env` already has working
     * credentials in it — they silently destroy real data. Requiring the name
     * to say "test" makes that mistake loud instead.
     */
    private function assertSafeToDestroy(string $databaseName): void
    {
        if ($this->env('CI', '') !== '' || $this->env('ALLOW_DESTRUCTIVE_TESTS', '') === '1') {
            return;
        }

        if (!str_contains(strtolower($databaseName), 'test')) {
            self::markTestSkipped(sprintf(
                'Refusing to truncate "%s": these tests empty every table. Point DB_NAME at a test '
                . 'database (for example %s_test), or set ALLOW_DESTRUCTIVE_TESTS=1 to override.',
                $databaseName,
                $databaseName,
            ));
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->truncateAll();
        }

        // A test's container holds its own connection in a cycle of objects
        // that only the cycle collector frees; left to run when it chooses,
        // the suite outgrows PostgreSQL's 100 connections.
        gc_collect_cycles();
    }

    /**
     * Whether this test may destroy what it is pointed at.
     *
     * Exposed so a subclass that reaches for the database in its own
     * `tearDown()` can ask the same question rather than assuming the answer.
     */
    protected function mayTruncate(): bool
    {
        return $this->mayTruncate;
    }

    /**
     * Remove every row, in dependency order, so each test starts from a known
     * state without dropping and re-creating the schema each time.
     */
    protected function truncateAll(): void
    {
        if (!$this->mayTruncate) {
            // A skipped test still gets a tearDown, and `$this->db` is already
            // connected by then. Without this line, refusing to run against a
            // development database in `setUp()` does not stop `tearDown()`
            // deleting every row in it.
            return;
        }

        foreach (
            [
            'audit_log',
            'backup_rehearsals',
            'backup_copies',
            'backup_destinations',
            'backup_runs',
            'http_metrics',
            'logo_cache',
            'saved_views',
            'dashboard_cards',
            'api_tokens',
            'attachments',
            'webauthn_credentials',
            'recovery_codes',
            'user_totp',
            'notification_log',
            'notification_routes',
            'notification_channels',
            'notification_preferences',
            'trusted_hosts',
            'subscription_tags',
            'subscription_splits',
            'budget_alert_state',
            'budgets',
            'subscription_charges',
            'subscription_price_history',
            'subscriptions',
            'exchange_rates',
            'tags',
            'categories',
            'payment_methods',
            'auth_tokens',
            'auth_attempts',
            'sessions',
            'household_memberships',
            'households',
            'users',
            'instance_settings',
            ] as $table
        ) {
            $this->db->execute('DELETE FROM ' . $this->db->platform()->quoteIdentifier($table));
        }
    }

    private function assertSchemaPresent(): void
    {
        try {
            $this->db->fetchValue('SELECT COUNT(*) FROM ' . $this->db->platform()->quoteIdentifier('users'));
        } catch (PDOException $exception) {
            self::fail(
                'The database has no schema. Run `vendor/bin/phinx migrate` before the test suite. '
                . 'Details: ' . $exception->getMessage(),
            );
        }
    }

    protected function env(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return ($value === false || $value === '') ? $default : (string) $value;
    }
}
