<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\InstanceSettingsRepository;
use App\Repository\NotificationChannelRepository;
use App\Repository\UserRepository;
use App\Security\KeyRing;
use App\Service\InstanceSettingsService;
use App\Support\SystemClock;
use DateTimeZone;

/**
 * What a database dump shows of the recoverable secrets: ciphertext only, with
 * the application reading them back exactly as they were entered.
 */
final class SecretsAtRestTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (new UserRepository($this->db))->create('owner@example.test', 'Owner', 'hash');
    }

    public function testChannelConfigIsStoredAsCiphertextAndReadsBack(): void
    {
        $channels = $this->channels(new KeyRing('key-one'));
        $config = ['url' => 'https://gotify.example.com', 'token' => 'AsecretToken1'];

        $id = $channels->create($this->userId, 'gotify', 'Phone', $config);

        $raw = $this->rawConfig($id);
        self::assertStringStartsWith('v2:', $raw);
        self::assertStringNotContainsString('AsecretToken1', $raw);
        self::assertStringNotContainsString('gotify.example.com', $raw);
        self::assertSame($config, $channels->find($this->userId, $id)?->config);

        $channels->update(
            $this->userId,
            $id,
            'Phone',
            ['url' => 'https://gotify.example.com', 'token' => 'Rotated2'],
            true,
        );

        self::assertStringNotContainsString('Rotated2', $this->rawConfig($id));
        self::assertSame('Rotated2', $channels->find($this->userId, $id)?->config['token']);
    }

    public function testAChannelWrittenBeforeEncryptionStillReads(): void
    {
        $channels = $this->channels(new KeyRing('key-one'));
        $id = $channels->create($this->userId, 'gotify', 'Phone', []);
        $this->setRawConfig($id, '{"url":"https://gotify.example.com","token":"Plain1"}');

        self::assertSame(
            ['url' => 'https://gotify.example.com', 'token' => 'Plain1'],
            $channels->find($this->userId, $id)?->config,
        );
    }

    public function testAChannelNoKeyOpensReadsAsEmptyNotAsAnError(): void
    {
        $id = $this->channels(new KeyRing('key-one'))->create($this->userId, 'gotify', 'Phone', ['token' => 'x']);

        $channel = $this->channels(new KeyRing('key-two'))->find($this->userId, $id);

        self::assertNotNull($channel);
        self::assertSame([], $channel->config);
    }

    public function testAChannelUnderAPreviousKeyStillReads(): void
    {
        $id = $this->channels(new KeyRing('key-one'))->create($this->userId, 'gotify', 'Phone', ['token' => 'x']);

        self::assertSame(
            ['token' => 'x'],
            $this->channels(new KeyRing('key-two', ['key-one']))->find($this->userId, $id)?->config,
        );
    }

    public function testTheRateProviderKeyIsStoredAsCiphertextAndReadsBack(): void
    {
        $settings = $this->settings(new KeyRing('key-one'));

        $settings->setRateProviderKey('fixer-key-123');

        $raw = (new InstanceSettingsRepository($this->db))->all()[InstanceSettingsService::KEY_RATE_PROVIDER_KEY];
        self::assertStringStartsWith('v2:', $raw);
        self::assertStringNotContainsString('fixer-key-123', $raw);
        self::assertSame('fixer-key-123', $this->settings(new KeyRing('key-one'))->storedRateProviderKey());

        $settings->setRateProviderKey('');
        self::assertSame(
            '',
            (new InstanceSettingsRepository($this->db))->all()[InstanceSettingsService::KEY_RATE_PROVIDER_KEY] ?? '',
        );
        self::assertSame('', $this->settings(new KeyRing('key-one'))->storedRateProviderKey());
    }

    public function testARateKeyWrittenBeforeEncryptionStillReads(): void
    {
        (new InstanceSettingsRepository($this->db))->set(InstanceSettingsService::KEY_RATE_PROVIDER_KEY, 'plain-key');

        self::assertSame('plain-key', $this->settings(new KeyRing('key-one'))->storedRateProviderKey());
    }

    public function testARateKeyNoKeyOpensReadsAsNoKey(): void
    {
        $this->settings(new KeyRing('key-one'))->setRateProviderKey('fixer-key-123');

        self::assertSame('', $this->settings(new KeyRing('key-two'))->storedRateProviderKey());
    }

    private function channels(KeyRing $ring): NotificationChannelRepository
    {
        return new NotificationChannelRepository($this->db, new SystemClock(new DateTimeZone('UTC')), $ring);
    }

    private function settings(KeyRing $ring): InstanceSettingsService
    {
        return new InstanceSettingsService(new InstanceSettingsRepository($this->db), $ring);
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
