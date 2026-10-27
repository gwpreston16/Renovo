<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\NotificationChannelRepository;
use App\Repository\UserRepository;
use App\Security\KeyRing;
use App\Support\FrozenClock;
use App\Tests\Integration\DatabaseTestCase;

/**
 * `bin/console secrets:rotate` as an operator runs it: a real process, its
 * exit code and what it says. The service underneath is covered by
 * SecretsRotationTest; this is the part an operator reads and a script checks.
 */
final class SecretsRotateCommandTest extends DatabaseTestCase
{
    private const OLD = 'old-secrets-key-for-the-rotate-command';
    private const NEW = 'new-secrets-key-for-the-rotate-command';
    private const SESSION = 'session-key-for-the-rotate-command-test';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (new UserRepository($this->db))->create('rotate@example.test', 'Rotate', 'hash');
    }

    public function testAValueNoKeyOpensIsLeftInPlaceAndTheCommandExitsTwo(): void
    {
        $readable = $this->channel(self::OLD, 'Readable');
        $lost = $this->channel('a-key-nobody-has', 'Lost');
        $rawLost = $this->rawConfig($lost);
        $rawReadable = $this->rawConfig($readable);

        [$code, $out, $err] = $this->console(['secrets:rotate'], self::NEW, self::OLD);

        self::assertSame(2, $code);
        self::assertStringContainsString(
            sprintf('channel_configs: 1 re-encrypted, 0 already under the current key, 1 unreadable (ids %d)', $lost),
            $out,
        );
        self::assertStringContainsString('could not be opened by any configured key', $err);
        self::assertStringContainsString('SECRETS_PREVIOUS_KEYS', $err);
        self::assertSame($rawLost, $this->rawConfig($lost), 'An unreadable value is never touched.');
        self::assertNotSame($rawReadable, $this->rawConfig($readable), 'The readable one was re-encrypted.');
        self::assertSame(
            'https://gotify.example.com',
            $this->channels(self::NEW)->find($this->userId, $readable)?->config['url'] ?? null,
        );
    }

    public function testAnUnknownOptionIsRefusedBeforeAnythingIsRead(): void
    {
        $id = $this->channel(self::OLD, 'Untouched');
        $before = $this->rawConfig($id);

        [$code, $out, $err] = $this->console(['secrets:rotate', '--dry-runn'], self::NEW, self::OLD);

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('Unknown option "--dry-runn"', $err);
        self::assertStringContainsString('Usage: secrets:rotate [--dry-run]', $err);
        self::assertSame($before, $this->rawConfig($id));
    }

    public function testADryRunReportsWhatWouldChangeAndWritesNothing(): void
    {
        $id = $this->channel(self::OLD, 'Dry');
        $before = $this->rawConfig($id);

        [$code, $out, $err] = $this->console(['secrets:rotate', '--dry-run'], self::NEW, self::OLD);

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('channel_configs: 1 would be re-encrypted', $out);
        self::assertStringNotContainsString(' re-encrypted,', str_replace('would be re-encrypted', '', $out));
        self::assertSame($before, $this->rawConfig($id));
        self::assertNull($this->db->fetchOne("SELECT * FROM audit_log WHERE action = 'secrets.rotated'"));
    }

    public function testARealRunReEncryptsAndAuditsIt(): void
    {
        $id = $this->channel(self::OLD, 'Real');

        [$code, $out, $err] = $this->console(['secrets:rotate'], self::NEW, self::OLD);

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('channel_configs: 1 re-encrypted, 0 already under the current key', $out);
        self::assertNotNull($this->db->fetchOne("SELECT * FROM audit_log WHERE action = 'secrets.rotated'"));
        self::assertSame('Real', $this->channels(self::NEW)->find($this->userId, $id)?->label);
    }

    public function testWithoutASecretsKeyItWarnsThatTheSessionKeyIsTheOne(): void
    {
        [$code, , $err] = $this->console(['secrets:rotate'], '', '');

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('SECRETS_KEY is not set', $err);
        self::assertStringContainsString('TOTP_ENCRYPTION_KEY or SESSION_KEY', $err);
    }

    public function testWithASecretsKeyThereIsNoWarning(): void
    {
        [$code, , $err] = $this->console(['secrets:rotate'], self::NEW, self::OLD);

        self::assertSame(0, $code);
        self::assertStringNotContainsString('SECRETS_KEY is not set', $err);
    }

    private function channels(string $key): NotificationChannelRepository
    {
        return new NotificationChannelRepository(
            $this->db,
            FrozenClock::at('2026-10-27 12:00:00'),
            new KeyRing($key),
        );
    }

    private function channel(string $key, string $name): int
    {
        return $this->channels($key)->create($this->userId, 'gotify', $name, [
            'url' => 'https://gotify.example.com',
            'token' => 'AsecretToken1',
        ]);
    }

    private function rawConfig(int $id): string
    {
        return (string) $this->db->fetchValue('SELECT config FROM notification_channels WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string} Exit code, stdout, stderr.
     */
    private function console(array $arguments, string $secretsKey, string $previousKeys): array
    {
        $driver = $this->env('DB_DRIVER', 'pgsql');
        $environment = [
            ...getenv(),
            'DB_DRIVER' => $driver,
            'DB_HOST' => $this->env('DB_HOST', '127.0.0.1'),
            'DB_PORT' => $this->env('DB_PORT', $driver === 'mysql' ? '3306' : '5432'),
            'DB_NAME' => $this->env('DB_NAME', 'renovo'),
            'DB_USER' => $this->env('DB_USER', 'renovo'),
            'DB_PASSWORD' => $this->env('DB_PASSWORD', 'renovo'),
            'SESSION_KEY' => self::SESSION,
            'TOTP_ENCRYPTION_KEY' => '',
            'SECRETS_KEY' => $secretsKey,
            'SECRETS_PREVIOUS_KEYS' => $previousKeys,
        ];

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/console', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
        );
        self::assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
