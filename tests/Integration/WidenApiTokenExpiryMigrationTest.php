<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\TokenScopes;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Support\FrozenClock;
use DateTimeImmutable;
use PDO;
use Phinx\Db\Adapter\AdapterInterface;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The Phase 83 migration (K186), run against the real `api_tokens` table:
 * `up()` makes `expires_at` a `datetime` without changing a value, a second
 * run changes nothing, `down()` clamps what a `timestamp` cannot hold, and
 * PostgreSQL is left alone.
 */
final class WidenApiTokenExpiryMigrationTest extends DatabaseTestCase
{
    private const FILE = '20271101000001_widen_api_token_expiry.php';

    /** @var list<string> */
    private array $executed = [];
    private int $users = 0;

    protected function tearDown(): void
    {
        // Whatever a test did to the column, leave it as the schema has it.
        if ($this->mayTruncate() && $this->isMysql()) {
            $this->db->execute('ALTER TABLE api_tokens MODIFY expires_at DATETIME NULL DEFAULT NULL');
        }

        parent::tearDown();
    }

    public function testUpWidensATimestampAndKeepsItsValues(): void
    {
        $this->needsMysql();
        $this->narrowTheColumn();
        $this->insertToken('2037-06-01 12:30:45');

        $this->migrate('up');

        self::assertSame('datetime', $this->columnType());
        self::assertSame('2037-06-01 12:30:45', $this->storedExpiry());
        // The wider column takes what the old one refused.
        $this->insertToken('2040-01-01 23:59:59');
        self::assertContains('2040-01-01 23:59:59', $this->allExpiries());
    }

    public function testUpTwiceChangesNothing(): void
    {
        $this->needsMysql();
        $this->narrowTheColumn();

        $this->migrate('up');
        $this->insertToken('2040-01-01 23:59:59');
        $this->executed = [];
        $this->migrate('up');

        self::assertSame([], $this->executed);
        self::assertSame('datetime', $this->columnType());
        self::assertSame('2040-01-01 23:59:59', $this->storedExpiry(), 'the repeat run left the value alone');
    }

    public function testDownClampsALaterExpiryAndRestoresTheTimestamp(): void
    {
        $this->needsMysql();
        $this->migrate('up');
        $this->insertToken('2040-01-01 23:59:59');
        $this->insertToken('2030-05-05 05:05:05');

        $this->migrate('down');

        self::assertSame('timestamp', $this->columnType());
        $expiries = $this->allExpiries();
        sort($expiries);
        self::assertSame(['2030-05-05 05:05:05', '2038-01-19 03:14:07'], $expiries);
    }

    public function testItIsANoOpOnPostgres(): void
    {
        $this->migrate('up', 'pgsql');
        $this->migrate('down', 'pgsql');

        self::assertSame([], $this->executed);
    }

    private function needsMysql(): void
    {
        if (!$this->isMysql()) {
            self::markTestSkipped('Only MySQL and MariaDB have a timestamp that stops at 2038.');
        }
    }

    private function isMysql(): bool
    {
        return $this->db->platform()->name() === 'mysql';
    }

    /**
     * Put the column back as it was before this migration.
     */
    private function narrowTheColumn(): void
    {
        $this->db->execute('ALTER TABLE api_tokens MODIFY expires_at TIMESTAMP NULL DEFAULT NULL');
    }

    private function insertToken(string $expiresAt): void
    {
        $clock = FrozenClock::at('2026-11-06 09:00:00');
        $users = new UserRepository($this->db);
        $userId = $users->create('owner' . ++$this->users . '@example.test', 'Owner', 'hash');

        (new ApiTokenRepository($this->db, $clock))->create(
            $userId,
            null,
            'Token ' . $expiresAt,
            bin2hex(random_bytes(8)),
            bin2hex(random_bytes(32)),
            TokenScopes::readEverything(),
            new DateTimeImmutable($expiresAt),
        );
    }

    private function columnType(): string
    {
        return strtolower((string) $this->db->fetchValue(
            'SELECT data_type FROM information_schema.columns'
            . " WHERE table_schema = DATABASE() AND table_name = 'api_tokens' AND column_name = 'expires_at'",
        ));
    }

    private function storedExpiry(): string
    {
        return (string) $this->db->fetchValue('SELECT expires_at FROM api_tokens ORDER BY id LIMIT 1');
    }

    /**
     * @return list<string>
     */
    private function allExpiries(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['expires_at'],
            $this->db->fetchAll('SELECT expires_at FROM api_tokens'),
        );
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

        $migration = new \WidenApiTokenExpiry('testing', 20271101000001);
        $migration->setAdapter($adapter);
        $migration->setOutput(new BufferedOutput());
        $migration->{$direction}();
    }
}
