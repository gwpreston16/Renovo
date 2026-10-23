<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\InstanceSettingsRepository;
use App\Repository\NotificationChannelRepository;
use App\Repository\UserRepository;
use App\Service\InstanceSettingsService;
use App\Support\SystemClock;
use App\Tests\Support\TestKeys;
use DateTimeZone;
use PDOStatement;
use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Migration\AbstractMigration;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The Phase 40 backfills, run against the test database: plaintext rows become
 * ciphertext, a second run changes nothing, and rolling back restores the
 * plaintext the application wrote before.
 */
final class SecretsBackfillMigrationTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (new UserRepository($this->db))->create('owner@example.test', 'Owner', 'hash');
    }

    public function testChannelConfigBackfillEncryptsReRunsCleanlyAndRollsBack(): void
    {
        $plain = '{"url":"https://gotify.example.com","token":"Plain1"}';
        $ids = [];
        foreach (range(1, 3) as $n) {
            $ids[] = $id = $this->channels()->create($this->userId, 'gotify', 'Phone ' . $n, []);
            $this->setRawConfig($id, $plain);
        }

        $this->migrate('20270401000001_encrypt_notification_channel_config.php', 'up');

        $encrypted = array_map($this->rawConfig(...), $ids);
        foreach ($encrypted as $raw) {
            self::assertStringStartsWith('v2:', $raw);
            self::assertStringNotContainsString('Plain1', $raw);
        }
        self::assertSame('Plain1', $this->channels()->find($this->userId, $ids[0])?->config['token']);

        $this->migrate('20270401000001_encrypt_notification_channel_config.php', 'up');
        self::assertSame($encrypted, array_map($this->rawConfig(...), $ids));

        $this->migrate('20270401000001_encrypt_notification_channel_config.php', 'down');
        self::assertSame([$plain, $plain, $plain], array_map($this->rawConfig(...), $ids));
    }

    public function testRollingBackLeavesARowNoKeyOpens(): void
    {
        $id = $this->channels()->create($this->userId, 'gotify', 'Phone', []);
        $this->setRawConfig($id, 'v2:deadbeef:AAAA');

        $output = $this->migrate('20270401000001_encrypt_notification_channel_config.php', 'down');

        self::assertSame('v2:deadbeef:AAAA', $this->rawConfig($id));
        self::assertStringContainsString('could not be decrypted', $output);
    }

    public function testEveryBatchIsEncrypted(): void
    {
        $ids = [];
        foreach (range(1, 201) as $n) {
            $ids[] = $id = $this->channels()->create($this->userId, 'ntfy', 'Topic ' . $n, []);
            $this->setRawConfig($id, '{"topic":"t' . $n . '"}');
        }

        $this->migrate('20270401000001_encrypt_notification_channel_config.php', 'up');

        foreach ($ids as $id) {
            self::assertStringStartsWith('v2:', $this->rawConfig($id));
        }
    }

    public function testAnEmptyDatabaseNeedsNoKey(): void
    {
        $saved = $_ENV['SESSION_KEY'] ?? null;
        unset($_ENV['SESSION_KEY']);

        try {
            $this->migrate('20270401000001_encrypt_notification_channel_config.php', 'up');
            $this->migrate('20270401000001_encrypt_notification_channel_config.php', 'down');
            $this->migrate('20270401000002_encrypt_rate_provider_key.php', 'up');
        } finally {
            $_ENV['SESSION_KEY'] = $saved;
        }

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM notification_channels'));
    }

    public function testAPlaceholderSessionKeyIsRefusedBeforeAnythingIsWritten(): void
    {
        $id = $this->channels()->create($this->userId, 'ntfy', 'Topic', []);
        $this->setRawConfig($id, '{"topic":"t"}');
        $saved = $_ENV['SESSION_KEY'] ?? null;
        $_ENV['SESSION_KEY'] = 'unused-by-migrations';

        try {
            $this->migrate('20270401000001_encrypt_notification_channel_config.php', 'up');
            self::fail('A placeholder key must stop the backfill.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('placeholder SESSION_KEY', $exception->getMessage());
        } finally {
            $_ENV['SESSION_KEY'] = $saved;
        }

        self::assertSame('{"topic":"t"}', $this->rawConfig($id));
    }

    public function testRateKeyBackfillEncryptsReRunsCleanlyAndRollsBack(): void
    {
        $repository = new InstanceSettingsRepository($this->db);
        $repository->set(InstanceSettingsService::KEY_RATE_PROVIDER_KEY, 'plain-key');

        $this->migrate('20270401000002_encrypt_rate_provider_key.php', 'up');

        $raw = $repository->all()[InstanceSettingsService::KEY_RATE_PROVIDER_KEY];
        self::assertStringStartsWith('v2:', $raw);
        self::assertSame(
            'plain-key',
            (new InstanceSettingsService($repository, TestKeys::ring()))->storedRateProviderKey(),
        );

        $this->migrate('20270401000002_encrypt_rate_provider_key.php', 'up');
        self::assertSame($raw, $repository->all()[InstanceSettingsService::KEY_RATE_PROVIDER_KEY]);

        $this->migrate('20270401000002_encrypt_rate_provider_key.php', 'down');
        self::assertSame('plain-key', $repository->all()[InstanceSettingsService::KEY_RATE_PROVIDER_KEY]);
    }

    public function testRateKeyBackfillWithNoStoredKeyDoesNothing(): void
    {
        $this->migrate('20270401000002_encrypt_rate_provider_key.php', 'up');
        $this->migrate('20270401000002_encrypt_rate_provider_key.php', 'down');

        self::assertArrayNotHasKey(
            InstanceSettingsService::KEY_RATE_PROVIDER_KEY,
            (new InstanceSettingsRepository($this->db))->all(),
        );
    }

    /**
     * Run a migration against the test database through an adapter that hands
     * each statement to it, and return what it wrote to the console.
     */
    private function migrate(string $file, string $direction): string
    {
        require_once __DIR__ . '/../../migrations/' . $file;

        $pdo = $this->db->connection();
        $prepare = static function (string $sql, array $params) use ($pdo): PDOStatement {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);

            return $statement;
        };

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('execute')->willReturnCallback(
            static fn (string $sql, array $params = []): int => $prepare($sql, $params)->rowCount(),
        );
        $adapter->method('query')->willReturnCallback(
            static fn (string $sql, array $params = []): PDOStatement => $prepare($sql, $params),
        );

        preg_match('/^(\d+)_(.+)\.php$/', $file, $match);
        $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $match[2])));

        /** @var AbstractMigration $migration */
        $migration = new $class('testing', (int) $match[1]);
        $migration->setAdapter($adapter);
        $output = new BufferedOutput();
        $migration->setOutput($output);
        $migration->{$direction}();

        return $output->fetch();
    }

    private function channels(): NotificationChannelRepository
    {
        return new NotificationChannelRepository($this->db, new SystemClock(new DateTimeZone('UTC')), TestKeys::ring());
    }

    private function rawConfig(int $id): string
    {
        $row = $this->db->fetchOne('SELECT config FROM notification_channels WHERE id = :id', ['id' => $id]);
        self::assertNotNull($row);

        return (string) $row['config'];
    }

    private function setRawConfig(int $id, string $config): void
    {
        $this->db->execute(
            'UPDATE notification_channels SET config = :config WHERE id = :id',
            ['config' => $config, 'id' => $id],
        );
    }
}
