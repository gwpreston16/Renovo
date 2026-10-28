<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\UserRepository;
use App\Repository\WebAuthnCredentialRepository;
use DateTimeImmutable;

/**
 * Passkey credential ids and user handles are base64url, where case matters
 * (Phase 65, K32). On MySQL the columns used to compare case-insensitively, so
 * two ids differing only by case collided on the unique index and a lookup
 * matched the wrong one. Both engines must keep them apart.
 */
final class PasskeyIdCaseTest extends DatabaseTestCase
{
    public function testIdsDifferingOnlyByCaseAreDistinct(): void
    {
        $credentials = new WebAuthnCredentialRepository($this->db);
        $userId = $this->makeUser('passkeys@example.test');
        $now = new DateTimeImmutable('2026-10-28 09:00:00');

        $lower = $credentials->create($userId, 'abcDEF_-123', '{}', 'Lower', null, [], 0, true, $now);
        $upper = $credentials->create($userId, 'ABCdef_-123', '{}', 'Upper', null, [], 0, true, $now);

        self::assertNotSame($lower, $upper);
        self::assertSame(2, $credentials->countForUser($userId));
        self::assertSame($lower, $credentials->findByCredentialId('abcDEF_-123')?->id);
        self::assertSame($upper, $credentials->findByCredentialId('ABCdef_-123')?->id);
        self::assertNull($credentials->findByCredentialId('ABCDEF_-123'));
    }

    public function testHandlesDifferingOnlyByCaseAreDistinct(): void
    {
        $users = new UserRepository($this->db);
        $first = $this->makeUser('first@example.test');
        $second = $this->makeUser('second@example.test');

        $users->setWebauthnHandle($first, 'hAnDlE-xyz');
        $users->setWebauthnHandle($second, 'HaNdLe-XYZ');

        self::assertSame($first, $users->findByWebauthnHandle('hAnDlE-xyz')?->id);
        self::assertSame($second, $users->findByWebauthnHandle('HaNdLe-XYZ')?->id);
        self::assertNull($users->findByWebauthnHandle('handle-xyz'));
    }

    private function makeUser(string $email): int
    {
        return (new UserRepository($this->db))->create($email, 'Passkey user', 'hash');
    }
}
