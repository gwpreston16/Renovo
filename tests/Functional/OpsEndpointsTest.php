<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The endpoints an operator's tools talk to.
 *
 * The interesting cases are the negative ones: readiness must say no when
 * something is wrong, and /metrics must say nothing at all to somebody who has
 * not been given the token.
 */
final class OpsEndpointsTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private InstanceSettingsService $settings;

    private int $adminId;
    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $this->settings = $container->get(InstanceSettingsService::class);
        $this->settings->markSetupComplete('2026-01-01 00:00:00');

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $now = new DateTimeImmutable();
        $this->adminId = $users->create('admin@example.test', 'Admin', 'hash', true, $now);
        $this->memberId = $users->create('member@example.test', 'Member', 'hash', false, $now);

        $householdId = $households->create('Ops household', $this->adminId);
        $memberships->create($householdId, $this->adminId, Role::OwnerAdmin);
        $memberships->create($householdId, $this->memberId, Role::Editor);
    }

    public function testLivenessAnswersWithoutTouchingAnythingElse(): void
    {
        $response = $this->get('/healthz');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $this->json($response)['status'] ?? null);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testReadinessPassesOnAMigratedDatabase(): void
    {
        $this->settings->markSchedulerRun(new DateTimeImmutable('-1 hour'));

        $response = $this->get('/readyz');
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $body['status'] ?? null);
        self::assertSame('ok', $body['checks']['database']['status'] ?? null);
        self::assertSame('ok', $body['checks']['migrations']['status'] ?? null);
    }

    /**
     * The check that exists because the failure is invisible otherwise: a
     * scheduler container that died stops every reminder without changing
     * anything a page would show.
     */
    public function testReadinessFailsWhenTheSchedulerHasStopped(): void
    {
        $this->settings->markSchedulerRun(new DateTimeImmutable('-5 days'));

        $response = $this->get('/readyz');
        $body = $this->json($response);

        self::assertSame(503, $response->getStatusCode(), 'A stale scheduler must take the instance out of service.');
        self::assertSame('fail', $body['status'] ?? null);
        self::assertSame('fail', $body['checks']['scheduler']['status'] ?? null);
    }

    public function testAFreshInstanceIsReadyBeforeTheSchedulerHasEverRun(): void
    {
        $response = $this->get('/readyz');

        self::assertSame(200, $response->getStatusCode(), 'A new deployment must not be held out of service.');
        self::assertSame('unknown', $this->json($response)['checks']['scheduler']['status'] ?? null);
    }

    /**
     * The health endpoints are reached before anybody has opened a browser, so
     * the first-run guard must not redirect them.
     */
    public function testHealthAnswersOnAnInstanceThatHasNeverBeenSetUp(): void
    {
        $this->db->execute('DELETE FROM instance_settings');

        self::assertSame(200, $this->get('/healthz')->getStatusCode());
        self::assertContains($this->get('/readyz')->getStatusCode(), [200, 503]);
    }

    public function testMetricsAreInvisibleWithoutACredential(): void
    {
        self::assertSame(
            404,
            $this->get('/metrics')->getStatusCode(),
            'An endpoint somebody may not read should not confirm that it exists.',
        );
    }

    public function testAnInstanceAdministratorMayReadMetricsInABrowser(): void
    {
        $this->signIn($this->adminId);

        $response = $this->get('/metrics');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('renovo_up 1', (string) $response->getBody());
    }

    public function testAnOrdinaryMemberMayNot(): void
    {
        $this->signIn($this->memberId);

        self::assertSame(404, $this->get('/metrics')->getStatusCode());
    }

    public function testTheExpositionCarriesTheBusinessNumbers(): void
    {
        $this->signIn($this->adminId);

        $body = (string) $this->get('/metrics')->getBody();

        foreach (
            [
            'renovo_users_total 2',
            'renovo_households_total 1',
            'renovo_subscriptions_total{state="active"}',
            'renovo_notifications_total{status="failed"}',
            'renovo_scheduler_last_run_timestamp_seconds',
            'renovo_http_requests_total{status="2xx"}',
            ] as $expected
        ) {
            self::assertStringContainsString($expected, $body);
        }

        // Every metric has its HELP and TYPE, or Prometheus reads them as
        // untyped and a dashboard cannot tell a counter from a gauge.
        self::assertStringContainsString('# TYPE renovo_users_total gauge', $body);
        self::assertStringContainsString('# TYPE renovo_notifications_total counter', $body);
    }

    public function testTheExpositionCarriesInstanceBackups(): void
    {
        $this->signIn($this->adminId);
        $body = (string) $this->get('/metrics')->getBody();

        // Before any backup: every series, at zero.
        self::assertStringContainsString('renovo_backup_last_success_timestamp_seconds 0', $body);
        self::assertStringContainsString('renovo_backup_last_size_bytes 0', $body);
        self::assertStringContainsString('renovo_backup_runs_total{status="verified"} 0', $body);
        self::assertStringContainsString('renovo_backup_runs_total{status="failed"} 0', $body);
        self::assertStringContainsString('# TYPE renovo_backup_runs_total counter', $body);

        $runs = new \App\Repository\BackupRunRepository($this->db);
        $good = (int) $runs->claim(new \DateTimeImmutable('2026-06-01 03:00:00'), 'schedule');
        $runs->finish($good, 'verified', new \DateTimeImmutable('2026-06-01 03:00:10'), ['size_bytes' => 4096]);
        $bad = (int) $runs->claim(new \DateTimeImmutable('2026-06-02 03:00:00'), 'schedule');
        $runs->finish($bad, 'failed', new \DateTimeImmutable('2026-06-02 03:00:05'), ['error' => 'tampered']);

        $body = (string) $this->get('/metrics')->getBody();

        self::assertStringContainsString(
            'renovo_backup_last_success_timestamp_seconds '
            . (new \DateTimeImmutable('2026-06-01 03:00:10', new \DateTimeZone('UTC')))->getTimestamp(),
            $body,
        );
        self::assertStringContainsString('renovo_backup_last_size_bytes 4096', $body);
        self::assertStringContainsString('renovo_backup_runs_total{status="verified"} 1', $body);
        self::assertStringContainsString('renovo_backup_runs_total{status="failed"} 1', $body);
    }

    public function testTheExpositionCarriesEachDestinationAndTheRehearsalButNoCredential(): void
    {
        $this->signIn($this->adminId);
        $destinations = new \App\Repository\BackupDestinationRepository(
            $this->db,
            \App\Support\FrozenClock::at('2026-06-01 00:00:00'),
            new \App\Security\KeyRing('metrics-test-key'),
        );
        $nas = $destinations->create(
            \App\Domain\BackupDestinationType::S3,
            'Office "NAS"',
            ['endpoint' => 'https://minio.lan', 'bucket' => 'b'],
            ['access_key_id' => 'AKIA-canary', 'secret_key' => 'canary-metrics-secret'],
            true,
            new \App\Service\Backup\BackupRetention(7, 4, 6),
        );

        $body = (string) $this->get('/metrics')->getBody();
        self::assertStringContainsString(
            'renovo_backup_offsite_last_success_timestamp_seconds{destination="Office \\"NAS\\""} 0',
            $body,
        );
        self::assertStringContainsString('renovo_backup_rehearsal_last_success_timestamp_seconds 0', $body);

        $runs = new \App\Repository\BackupRunRepository($this->db);
        $run = (int) $runs->claim(new \DateTimeImmutable('2026-06-01 03:00:00'), 'schedule');
        $runs->finish($run, 'verified', new \DateTimeImmutable('2026-06-01 03:00:10'), ['file' => 'x']);
        $copies = new \App\Repository\BackupCopyRepository($this->db);
        $copies->queue($run, $nas, 'x');
        $copy = $copies->pending()[0];
        $copies->confirm($copy->id, new \DateTimeImmutable('2026-06-01 03:05:00'));
        $rehearsals = new \App\Repository\BackupRehearsalRepository($this->db);
        $rehearsal = $rehearsals->start($copy->id, 'Office', 'download', new \DateTimeImmutable('2026-06-02 04:00:00'));
        $finished = new \DateTimeImmutable('2026-06-02 04:01:00');
        $rehearsals->finish($rehearsal, 'passed', 'download', null, null, $finished);

        $body = (string) $this->get('/metrics')->getBody();
        $utc = new \DateTimeZone('UTC');
        self::assertStringContainsString(
            '{destination="Office \\"NAS\\""} ' . (new \DateTimeImmutable('2026-06-01 03:05:00', $utc))->getTimestamp(),
            $body,
        );
        self::assertStringContainsString(
            'renovo_backup_rehearsal_last_success_timestamp_seconds '
            . (new \DateTimeImmutable('2026-06-02 04:01:00', $utc))->getTimestamp(),
            $body,
        );
        self::assertStringNotContainsString('canary', $body);
        self::assertStringNotContainsString('minio.lan', $body);
    }

    /**
     * With metrics configured, requests are counted by the status the client
     * actually got — which is only true while the counter sits outside the
     * error middleware. Inside it, every handled 404 and 403 would arrive as
     * an exception and be recorded as a 500, in the direction that matters
     * most on a dashboard.
     */
    public function testRequestsAreCountedByTheStatusTheClientSaw(): void
    {
        $token = 'a-metrics-token-for-this-test';
        $app = $this->appWithMetricsToken($token);

        $this->signIn($this->adminId);

        // A 404 the error middleware produced from an exception, and a page
        // that rendered normally.
        $app->handle($this->requestFor('/no-such-page'));
        $app->handle($this->requestFor('/healthz'));

        $body = (string) $app->handle(
            $this->requestFor('/metrics')->withHeader('Authorization', 'Bearer ' . $token),
        )->getBody();

        preg_match('/renovo_http_requests_total\{status="4xx"\} (\d+)/', $body, $client);
        preg_match('/renovo_http_requests_total\{status="5xx"\} (\d+)/', $body, $server);

        self::assertSame('1', $client[1] ?? null, 'The 404 belongs in the 4xx bucket.');
        self::assertSame('0', $server[1] ?? null, 'And nowhere near the 5xx one.');

        // The ops endpoints do not count themselves: a scrape every fifteen
        // seconds would otherwise be most of the traffic the numbers describe.
        preg_match('/renovo_http_requests_total\{status="2xx"\} (\d+)/', $body, $ok);
        self::assertSame('0', $ok[1] ?? null, '/healthz and /metrics are not traffic worth counting.');
    }

    /**
     * @return App<ContainerInterface>
     */
    private function appWithMetricsToken(string $token): App
    {
        // Set before the bootstrap runs: the settings file reads the
        // environment once, and the middleware stack is assembled from what it
        // found.
        $_ENV['METRICS_TOKEN'] = $token;

        try {
            $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';

            return $bootstrap(true, [
                SessionInterface::class => $this->session,
                MailerInterface::class => new RecordingMailer(),
            ]);
        } finally {
            unset($_ENV['METRICS_TOKEN']);
        }
    }

    private function requestFor(string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
    }

    private function signIn(int $userId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'http://localhost' . $path,
                ['REMOTE_ADDR' => '127.0.0.1'],
            ),
        );
    }
}
