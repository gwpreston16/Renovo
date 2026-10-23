<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Http\HttpClientOptions;
use App\Http\UrlGuard;
use App\Repository\AuditLogRepository;
use App\Repository\MembershipRepository;
use App\Repository\TrustedHostRepository;
use App\Security\RequestContext;
use App\Security\RequestContextHolder;
use App\Service\AuditLogService;
use App\Service\TrustedHostService;
use App\Service\ValidationException;
use App\Support\FrozenClock;
use App\Tests\Support\CapturingLogger;
use App\Tests\Support\FakeDnsResolver;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\UriFactory;

/**
 * The trusted-host list as the SSRF guard reads it, from the database (K65):
 * what is stored is what `entries()` returns, bad entries never get in, and a
 * change reaches the guard without waiting for a new process. LAN backup
 * destinations (Phase 39) depend on every part of it.
 */
final class TrustedHostServiceTest extends DatabaseTestCase
{
    public function testEntriesAreReadFromTheDatabaseAndAChangeReachesTheGuardAtOnce(): void
    {
        $hosts = $this->service();
        $guard = new UrlGuard(
            new FakeDnsResolver(['nas.lan' => ['192.168.1.20']]),
            $hosts,
            new HttpClientOptions(),
            new NullLogger(),
        );
        $uri = (new UriFactory())->createUri('http://nas.lan/dav/');

        try {
            $guard->inspect($uri);
            self::fail('An untrusted private host was allowed.');
        } catch (\App\Http\BlockedTargetException) {
        }

        $hosts->add('  NAS.lan ', 'The NAS', null);
        $hosts->add('10.0.0.0/8', null, null);
        self::assertSame(['10.0.0.0/8', 'nas.lan'], $this->sorted($hosts->entries()));
        self::assertTrue($guard->inspect($uri)->isTrusted);

        $nas = array_values(array_filter($hosts->all(), static fn (array $row): bool => $row['pattern'] === 'nas.lan'));
        $hosts->remove($nas[0]['id'], null);
        self::assertSame(['10.0.0.0/8'], $hosts->entries());

        $this->expectException(\App\Http\BlockedTargetException::class);
        $guard->inspect($uri);
    }

    public function testEmptyTooLongInvalidAndDuplicateEntriesAreRefused(): void
    {
        $hosts = $this->service();
        $hosts->add('nas.lan', null, null);

        foreach (
            [
                '' => 'error.trusted_host.required',
                '   ' => 'error.trusted_host.required',
                str_repeat('a', 256) => 'error.trusted_host.too_long',
                'not a host' => 'error.trusted_host.invalid',
                '-bad.lan' => 'error.trusted_host.invalid',
                'NAS.LAN' => 'error.trusted_host.duplicate',
            ] as $pattern => $key
        ) {
            try {
                $hosts->add((string) $pattern, null, null);
                self::fail(sprintf('"%s" was accepted.', $pattern));
            } catch (ValidationException $exception) {
                self::assertSame($key, $exception->errors()['pattern']->key, (string) $pattern);
            }
        }

        self::assertSame(['nas.lan'], $this->service()->entries());
    }

    public function testAnAdditionIsLoggedAndAudited(): void
    {
        $logger = new CapturingLogger();
        $this->service($logger)->add('.home.arpa', 'everything at home', null);

        self::assertNotNull($this->db->fetchOne("SELECT * FROM audit_log WHERE action = 'trusted_host.added'"));
        self::assertStringContainsString('Trusted host added', implode("\n", $logger->lines));
    }

    private function service(?CapturingLogger $logger = null): TrustedHostService
    {
        return new TrustedHostService(
            new TrustedHostRepository($this->db, FrozenClock::at('2026-10-23 12:00:00')),
            new AuditLogService(
                new AuditLogRepository($this->db),
                new MembershipRepository($this->db),
                new RequestContextHolder(RequestContext::of('127.0.0.1', 'PHPUnit')),
                FrozenClock::at('2026-10-23 12:00:00'),
                new NullLogger(),
            ),
            $logger ?? new CapturingLogger(),
        );
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
