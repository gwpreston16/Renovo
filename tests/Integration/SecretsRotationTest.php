<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\AuditLogRepository;
use App\Repository\InstanceSettingsRepository;
use App\Repository\MembershipRepository;
use App\Repository\NotificationChannelRepository;
use App\Repository\StoredSecretRepository;
use App\Repository\TotpRepository;
use App\Repository\UserRepository;
use App\Security\KeyRing;
use App\Security\RequestContext;
use App\Security\RequestContextHolder;
use App\Security\SecretCipher;
use App\Service\AuditLogService;
use App\Service\InstanceSettingsService;
use App\Service\SecretsRotationService;
use App\Support\FrozenClock;
use Psr\Log\NullLogger;

/**
 * `secrets:rotate` after the operator moves the old key to the previous keys:
 * every value ends under the new key, still reads back, and nothing no key
 * opens is touched.
 */
final class SecretsRotationTest extends DatabaseTestCase
{
    private const SEED = 'JBSWY3DPEHPK3PXP';

    private FrozenClock $clock;
    private int $alice;
    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-10-23 12:00:00');
        $users = new UserRepository($this->db);
        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->bob = $users->create('bob@example.test', 'Bob', 'hash');
    }

    public function testEveryValueEndsUnderTheNewKeyAndStillReads(): void
    {
        $old = new KeyRing('key-one');
        $this->seed($old);
        $plainChannel = $this->channels($old)->create($this->bob, 'ntfy', 'Legacy', []);
        $this->db->execute(
            'UPDATE notification_channels SET config = :c WHERE id = :id',
            ['c' => '{"topic":"legacy-topic"}', 'id' => $plainChannel],
        );

        $report = $this->service(new KeyRing('key-two', ['key-one']))->rotate();

        self::assertSame(['rotated' => 1, 'current' => 0, 'unreadable' => []], $report['totp_secrets']);
        self::assertSame(['rotated' => 2, 'current' => 0, 'unreadable' => []], $report['channel_configs']);
        self::assertSame(['rotated' => 1, 'current' => 0, 'unreadable' => []], $report['rate_provider_key']);

        // Only the new key now: the old one could be removed.
        $new = new KeyRing('key-two');
        $seed = (new TotpRepository($this->db))->find($this->alice)['secret'] ?? '';
        self::assertSame(self::SEED, (new SecretCipher($new, SecretCipher::PURPOSE_TOTP))->decrypt($seed));
        self::assertSame(
            ['url' => 'https://gotify.example.com', 'token' => 'AsecretToken1'],
            $this->channels($new)->findAllForUser($this->alice)[0]->config,
        );
        self::assertSame(['topic' => 'legacy-topic'], $this->channels($new)->find($this->bob, $plainChannel)?->config);
        self::assertSame('fixer-key-123', $this->settings($new)->storedRateProviderKey());

        $entry = $this->db->fetchOne("SELECT * FROM audit_log WHERE action = 'secrets.rotated'");
        self::assertNotNull($entry);
        self::assertStringContainsString('"rotated":2', (string) $entry['context']);
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->seed(new KeyRing('key-one'));
        $ring = new KeyRing('key-two', ['key-one']);
        $this->service($ring)->rotate();
        $before = $this->raw();

        $report = $this->service($ring)->rotate();

        self::assertSame(0, $report['totp_secrets']['rotated'] + $report['channel_configs']['rotated']
            + $report['rate_provider_key']['rotated']);
        self::assertSame(1, $report['totp_secrets']['current']);
        self::assertSame($before, $this->raw());
    }

    public function testADryRunWritesNothing(): void
    {
        $this->seed(new KeyRing('key-one'));
        $before = $this->raw();

        $report = $this->service(new KeyRing('key-two', ['key-one']))->rotate(dryRun: true);

        self::assertSame(1, $report['channel_configs']['rotated']);
        self::assertSame($before, $this->raw());
        self::assertNull($this->db->fetchOne("SELECT * FROM audit_log WHERE action = 'secrets.rotated'"));
    }

    public function testAValueNoKeyOpensIsLeftAsItIsAndListed(): void
    {
        $this->seed(new KeyRing('key-one'));
        $lost = $this->channels(new KeyRing('lost-key'))->create($this->bob, 'gotify', 'Lost', ['token' => 'x']);
        $rawLost = $this->rawConfig($lost);

        $report = $this->service(new KeyRing('key-two', ['key-one']))->rotate();

        self::assertSame([$lost], $report['channel_configs']['unreadable']);
        self::assertSame($rawLost, $this->rawConfig($lost));
    }

    private function rawConfig(int $id): string
    {
        return (string) $this->db->fetchValue('SELECT config FROM notification_channels WHERE id = :id', ['id' => $id]);
    }

    private function seed(KeyRing $ring): void
    {
        (new TotpRepository($this->db))->startEnrolment(
            $this->alice,
            (new SecretCipher($ring, SecretCipher::PURPOSE_TOTP))->encrypt(self::SEED),
            $this->clock->now(),
        );
        $this->channels($ring)->create($this->alice, 'gotify', 'Phone', [
            'url' => 'https://gotify.example.com',
            'token' => 'AsecretToken1',
        ]);
        $this->settings($ring)->setRateProviderKey('fixer-key-123');
    }

    /**
     * @return list<string>
     */
    private function raw(): array
    {
        return array_map('strval', [
            $this->db->fetchValue('SELECT secret FROM user_totp'),
            ...array_column($this->db->fetchAll('SELECT config FROM notification_channels ORDER BY id'), 'config'),
            $this->db->fetchValue(
                "SELECT setting_value FROM instance_settings WHERE setting_key = 'rate_provider_key'",
            ),
        ]);
    }

    private function service(KeyRing $ring): SecretsRotationService
    {
        return new SecretsRotationService(
            new StoredSecretRepository($this->db),
            $ring,
            $this->db,
            new AuditLogService(
                new AuditLogRepository($this->db),
                new MembershipRepository($this->db),
                new RequestContextHolder(RequestContext::of('127.0.0.1', 'console')),
                $this->clock,
                new NullLogger(),
            ),
        );
    }

    private function channels(KeyRing $ring): NotificationChannelRepository
    {
        return new NotificationChannelRepository($this->db, $this->clock, $ring);
    }

    private function settings(KeyRing $ring): InstanceSettingsService
    {
        return new InstanceSettingsService(new InstanceSettingsRepository($this->db), $ring);
    }
}
