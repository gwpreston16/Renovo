<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\TokenScopes;
use App\Repository\ApiTokenRepository;
use App\Repository\AuditLogRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\RequestContextHolder;
use App\Service\ApiTokenService;
use App\Service\AuditLogService;
use App\Support\FrozenClock;
use DateTimeImmutable;
use Psr\Log\NullLogger;

/**
 * An API token can expire after January 2038 (K186). On MySQL/MariaDB the
 * `timestamp` column stopped at 2038-01-19 and issuing such a token was a
 * server error; `WidenApiTokenExpiry` made the column a `datetime`.
 */
final class ApiTokenLongExpiryTest extends DatabaseTestCase
{
    public function testATokenThatExpiresIn2040IsIssuedAndReadBackIntact(): void
    {
        $clock = FrozenClock::at('2026-11-06 09:00:00');
        $users = new UserRepository($this->db);
        $userId = $users->create('owner@example.test', 'Owner', 'hash');
        $repository = new ApiTokenRepository($this->db, $clock);
        $service = new ApiTokenService(
            $repository,
            $users,
            new AuditLogService(
                new AuditLogRepository($this->db),
                new MembershipRepository($this->db),
                new RequestContextHolder(),
                $clock,
                new NullLogger(),
            ),
            $clock,
        );
        $user = $users->findById($userId);
        self::assertNotNull($user);

        $service->issue(
            $user,
            null,
            'Long lived',
            TokenScopes::readEverything(),
            new DateTimeImmutable('2040-01-01 23:59:59'),
        );

        $tokens = $service->listFor($user);
        self::assertCount(1, $tokens);
        self::assertSame('2040-01-01 23:59:59', $tokens[0]->expiresAt?->format('Y-m-d H:i:s'));
    }
}
