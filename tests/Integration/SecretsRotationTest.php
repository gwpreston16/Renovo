<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BackupDestinationType;
use App\Domain\StoredSecretKind;
use App\Repository\BackupDestinationRepository;
use App\Service\Backup\BackupRetention;
use App\Service\Auth\TotpService;
use App\Service\Auth\RecoveryCodeService;
use App\Security\Totp;
use App\Security\PasswordHasher;
use App\Repository\WebAuthnCredentialRepository;
use App\Repository\RecoveryCodeRepository;
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

    public function testTwoFactorStillVerifiesUnderOnlyTheNewKey(): void
    {
        $this->seed(new KeyRing('key-one'));
        $step = Totp::stepAt($this->clock->now()->getTimestamp());
        (new TotpRepository($this->db))->confirm($this->alice, $this->clock->now(), $step - 10);

        $this->service(new KeyRing('key-two', ['key-one']))->rotate();

        $audit = $this->audit();
        $totp = new TotpService(
            new TotpRepository($this->db),
            new RecoveryCodeService(new RecoveryCodeRepository($this->db), new PasswordHasher(), $audit, $this->clock),
            new WebAuthnCredentialRepository($this->db),
            new SecretCipher(new KeyRing('key-two'), SecretCipher::PURPOSE_TOTP),
            new PasswordHasher(),
            $audit,
            $this->clock,
            new NullLogger(),
        );

        self::assertTrue($totp->verify($this->alice, Totp::codeForStep(self::SEED, $step)));
    }

    public function testEveryBatchIsRotatedAndAClearedRateKeyIsLeftAlone(): void
    {
        $old = new KeyRing('key-one');
        foreach (range(1, 201) as $n) {
            $this->channels($old)->create($this->bob, 'ntfy', 'Topic ' . $n, ['topic' => 't' . $n]);
        }
        $this->settings($old)->setRateProviderKey('');

        $report = $this->service(new KeyRing('key-two', ['key-one']))->rotate();

        self::assertSame(201, $report['channel_configs']['rotated']);
        self::assertSame(0, $report['rate_provider_key']['rotated'] + $report['rate_provider_key']['current']);
        $new = new SecretCipher(new KeyRing('key-two'), SecretCipher::PURPOSE_CHANNEL_CONFIG);
        foreach (array_column($this->db->fetchAll('SELECT config FROM notification_channels'), 'config') as $config) {
            self::assertTrue($new->isCurrent((string) $config));
        }
        self::assertSame('', (string) $this->db->fetchValue(
            "SELECT setting_value FROM instance_settings WHERE setting_key = 'rate_provider_key'",
        ));
    }

    public function testAValueRewrittenSinceItWasReadIsNotReplaced(): void
    {
        $id = $this->channels(new KeyRing('key-one'))->create($this->bob, 'ntfy', 'Topic', ['topic' => 'a']);
        $current = $this->rawConfig($id);

        $replaced = (new StoredSecretRepository($this->db))
            ->replace(StoredSecretKind::ChannelConfig, $id, 'v2:stale:value', 'v2:new:value');

        self::assertFalse($replaced);
        self::assertSame($current, $this->rawConfig($id));
    }

    public function testAPlaintextTwoFactorSeedIsReportedNotEncrypted(): void
    {
        (new TotpRepository($this->db))->startEnrolment($this->alice, self::SEED, $this->clock->now());

        $report = $this->service(new KeyRing('key-one'))->rotate();

        self::assertSame([$this->alice], $report['totp_secrets']['unreadable']);
        self::assertSame(self::SEED, (string) $this->db->fetchValue('SELECT secret FROM user_totp'));
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

    public function testBackupDestinationCredentialsRotateAndADirectoryWithNoneIsSkipped(): void
    {
        $old = new KeyRing('key-one');
        $s3 = $this->destinations($old)->create(
            BackupDestinationType::S3,
            'Bucket',
            ['endpoint' => 'https://s3.example.com', 'bucket' => 'b'],
            ['access_key_id' => 'AKIA-canary', 'secret_key' => 'canary-secret'],
            true,
            new BackupRetention(7, 4, 6),
        );
        $this->destinations($old)->create(
            BackupDestinationType::Directory,
            'NAS',
            ['path' => '/mnt/nas'],
            [],
            true,
            new BackupRetention(7, 4, 6),
        );

        $report = $this->service(new KeyRing('key-two', ['key-one']))->rotate();

        self::assertSame(['rotated' => 1, 'current' => 0, 'unreadable' => []], $report['backup_destination_secrets']);
        $after = $this->destinations(new KeyRing('key-two'))->find($s3);
        self::assertNotNull($after);
        self::assertFalse($after->secretsUnreadable);
        self::assertSame('canary-secret', $after->secrets['secret_key']);
        self::assertStringNotContainsString(
            'canary-secret',
            (string) $this->db->fetchValue('SELECT secret FROM backup_destinations WHERE id = :id', ['id' => $s3]),
        );
    }

    private function destinations(KeyRing $ring): BackupDestinationRepository
    {
        return new BackupDestinationRepository($this->db, $this->clock, $ring);
    }

    private function service(KeyRing $ring): SecretsRotationService
    {
        return new SecretsRotationService(
            new StoredSecretRepository($this->db),
            $ring,
            $this->db,
            $this->audit(),
        );
    }

    private function audit(): AuditLogService
    {
        return new AuditLogService(
            new AuditLogRepository($this->db),
            new MembershipRepository($this->db),
            new RequestContextHolder(RequestContext::of('127.0.0.1', 'console')),
            $this->clock,
            new NullLogger(),
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
