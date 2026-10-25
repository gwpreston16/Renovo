<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\MailDsn;
use PHPUnit\Framework\TestCase;

/**
 * The relay's certificate is checked unless the operator says otherwise
 * (Phase 43): for every scheme, not only the encrypted one.
 */
final class MailDsnTest extends TestCase
{
    public function testTheCertificateIsCheckedByDefaultOnEveryScheme(): void
    {
        self::assertSame('smtp://relay.example:587', MailDsn::fromSettings($this->mail(['encryption' => 'none'])));
        self::assertSame('smtps://relay.example:587', MailDsn::fromSettings($this->mail(['encryption' => 'tls'])));
        self::assertSame('smtps://relay.example:587', MailDsn::fromSettings($this->mail(['encryption' => 'ssl'])));
    }

    public function testSwitchingTheCheckOffAddsVerifyPeerZeroToEveryScheme(): void
    {
        self::assertSame(
            'smtp://relay.example:587?verify_peer=0',
            MailDsn::fromSettings($this->mail(['verify_peer' => false])),
        );
        self::assertSame(
            'smtps://relay.example:587?verify_peer=0',
            MailDsn::fromSettings($this->mail(['verify_peer' => false, 'encryption' => 'tls'])),
        );
    }

    public function testCredentialsAreEncodedIntoTheDsn(): void
    {
        self::assertSame(
            'smtp://me%40example.test:p%40ss%3Aword@relay.example:587',
            MailDsn::fromSettings($this->mail(['user' => 'me@example.test', 'password' => 'p@ss:word'])),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{host: string, port: int, user: string, password: string, encryption: string, verify_peer: bool}
     */
    private function mail(array $overrides = []): array
    {
        /** @var array{host: string, port: int, user: string, password: string, encryption: string, verify_peer: bool} */
        return $overrides + [
            'host' => 'relay.example',
            'port' => 587,
            'user' => '',
            'password' => '',
            'encryption' => 'none',
            'verify_peer' => true,
        ];
    }
}
