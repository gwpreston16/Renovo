<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\AttachmentStorage;
use App\Service\CatchUpService;
use App\Service\ChargeService;
use App\Service\InstanceSettingsService;
use App\Service\SubscriptionService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\FakeUpload;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The Payments card on a subscription's cost page, through the real
 * application: drawn with its controls for a member who may change the row,
 * without them for anybody else, and every action refused server-side to a
 * member the controls were not drawn for.
 */
final class PaymentsCardTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private ContainerInterface $container;

    private int $ownerId;
    private int $contributorId;
    private int $viewerId;
    private int $householdId;
    private int $subscriptionId;
    private int $otherSubscriptionId;
    private string $attachmentDirectory;
    private Scope $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $this->attachmentDirectory = sys_get_temp_dir() . '/renovo-payments-card-' . bin2hex(random_bytes(6));
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            AttachmentStorage::class => new AttachmentStorage($this->attachmentDirectory, 1024 * 1024),
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $this->container = $container;

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->markRatesAttempted(new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->contributorId = $users->create('bram@example.test', 'Bram', 'hash', false, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());

        $this->householdId = (new HouseholdRepository($this->db))->create('Household', $this->ownerId);
        $memberships = new MembershipRepository($this->db);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->contributorId, Role::Contributor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $this->owner = Scope::forMember(
            $this->ownerId,
            false,
            $this->householdId,
            Role::OwnerAdmin,
            IsolationMode::Shared,
        );

        $subscriptions = $container->get(SubscriptionService::class);
        $this->subscriptionId = $subscriptions->create($this->owner, $this->input('Energy'));
        $this->otherSubscriptionId = $subscriptions->create($this->owner, $this->input('Water'));

        $container->get(CatchUpService::class)->run($this->owner);
    }

    public function testTheOwnerSeesThePaymentsAndTheirControls(): void
    {
        $html = (string) $this->get($this->ownerId)->getBody();

        self::assertStringContainsString('id="payments"', $html);
        self::assertStringContainsString('/charges/' . $this->firstCharge() . '/actual', $html);
        self::assertStringContainsString('action="/subscriptions/' . $this->subscriptionId . '/charges"', $html);

        // Each row's control is named for its date, and an invoice uploaded
        // from it comes back to the card.
        self::assertMatchesRegularExpression('#Change<span class="visually-hidden"> \d{1,2} \w+ \d{4}</span>#', $html);
        self::assertStringContainsString('value="/subscriptions/' . $this->subscriptionId . '/money#payments"', $html);
    }

    public function testOpeningTheCostPageCatchesUpFirst(): void
    {
        $id = $this->container->get(SubscriptionService::class)->create($this->owner, $this->input('Gas'));
        self::assertSame([], $this->charges()->forSubscription($this->owner, $id));

        $this->signIn($this->ownerId);
        $page = $this->app->handle((new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/subscriptions/' . $id . '/money',
        ));

        self::assertSame(200, $page->getStatusCode());
        self::assertCount(2, $this->charges()->forSubscription($this->owner, $id));
    }

    public function testAViewerAndAContributorOnSomebodyElsesRowSeeNoControls(): void
    {
        foreach ([$this->viewerId, $this->contributorId] as $userId) {
            $response = $this->get($userId);
            self::assertSame(200, $response->getStatusCode());

            $html = (string) $response->getBody();
            self::assertStringContainsString('id="payments"', $html);
            self::assertStringNotContainsString('/actual"', $html);
            self::assertStringNotContainsString('/skip"', $html);
            self::assertStringNotContainsString('/undo"', $html);
            self::assertStringNotContainsString('/charges"', $html);
        }
    }

    public function testRecordingAnAmountThroughTheCard(): void
    {
        $charge = $this->firstCharge();

        $response = $this->post($this->ownerId, $this->chargePath($charge, 'actual'), [
            'actual' => '63.20',
            'note' => 'Winter bill',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            '/subscriptions/' . $this->subscriptionId . '/money#payments',
            $response->getHeaderLine('Location'),
        );
        self::assertSame(6320, $this->charges()->find($this->owner, $charge)?->actualMinor);

        $html = (string) $this->get($this->ownerId)->getBody();
        self::assertStringContainsString('Winter bill', $html);
        self::assertStringContainsString('payments-expected', $html);
    }

    public function testSkippingUndoingAndAddingThroughTheCard(): void
    {
        $charge = $this->firstCharge();
        $base = '/subscriptions/' . $this->subscriptionId . '/charges';

        $this->post($this->ownerId, $base . '/' . $charge . '/skip');
        self::assertTrue($this->charges()->find($this->owner, $charge)?->isSkipped());

        $this->post($this->ownerId, $base . '/' . $charge . '/undo');
        self::assertFalse($this->charges()->find($this->owner, $charge)->isAdjusted());

        $yesterday = (new DateTimeImmutable('-1 day'))->format('Y-m-d');
        $this->post($this->ownerId, $base, ['date' => $yesterday, 'amount' => '4.00']);
        self::assertCount(3, $this->charges()->forSubscription($this->owner, $this->subscriptionId));
    }

    public function testUndoSaysWhetherAPaymentWasRemovedOrPutBack(): void
    {
        $charge = $this->firstCharge();
        $base = '/subscriptions/' . $this->subscriptionId . '/charges';

        $this->post($this->ownerId, $base . '/' . $charge . '/skip');
        $this->session->set('_flashes', []);
        $this->post($this->ownerId, $base . '/' . $charge . '/undo');
        self::assertSame(['flash.charge_undone'], $this->flashes());

        // A fee off the weekly cycle: added by hand, and still manual.
        $fee = $this->charges()->addManual($this->owner, $this->subscriptionId, [
            'date' => (new DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'amount' => '4.00',
        ]);
        // Its date carries the catalogue's hint for a payment added by hand.
        self::assertStringContainsString(
            '<span class="hint">added by hand</span>',
            (string) $this->get($this->ownerId)->getBody(),
        );
        $this->session->set('_flashes', []);
        $this->post($this->ownerId, $base . '/' . $fee->id . '/undo');
        self::assertSame(['flash.charge_removed'], $this->flashes());
        self::assertNull($this->charges()->find($this->owner, $fee->id));
    }

    /**
     * @return list<string>
     */
    private function flashes(): array
    {
        $flashes = $this->session->get('_flashes');

        return array_values(array_map(
            static fn (mixed $flash): string => is_array($flash) ? (string) ($flash['message'] ?? '') : '',
            is_array($flashes) ? $flashes : [],
        ));
    }

    public function testAResumedRowsCardMarksThePausedMonths(): void
    {
        $subscriptions = $this->container->get(SubscriptionService::class);
        $paused = $subscriptions->create($this->owner, $this->input('Paused') + ['is_active' => '0']);
        $subscriptions->setActive($this->owner, $paused, true);

        $this->signIn($this->ownerId);
        $html = (string) $this->app->handle((new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/subscriptions/' . $paused . '/money',
        ))->getBody();

        self::assertSame(2, substr_count($html, '<span class="hint">paused</span>'));
    }

    public function testAContributorIsRefusedOnARowTheyDoNotOwn(): void
    {
        $charge = $this->firstCharge();

        $this->post($this->contributorId, $this->chargePath($charge, 'actual'), [
            'actual' => '1.00',
        ]);
        $this->post($this->contributorId, '/subscriptions/' . $this->subscriptionId . '/charges', [
            'date' => (new DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'amount' => '1.00',
        ]);

        self::assertFalse($this->charges()->find($this->owner, $charge)?->isAdjusted());
        self::assertCount(2, $this->charges()->forSubscription($this->owner, $this->subscriptionId));
    }

    public function testAChargeIsOnlyReachedThroughItsOwnSubscription(): void
    {
        $charge = $this->firstCharge();

        $this->post($this->ownerId, '/subscriptions/' . $this->otherSubscriptionId . '/charges/' . $charge . '/skip');

        self::assertFalse($this->charges()->find($this->owner, $charge)?->isSkipped());
    }

    public function testAViewerIsRefusedEveryChargeRoute(): void
    {
        $charge = $this->firstCharge();
        $base = '/subscriptions/' . $this->subscriptionId . '/charges';

        $paths = [
            $base,
            $this->chargePath($charge, 'actual'),
            $this->chargePath($charge, 'skip'),
            $this->chargePath($charge, 'undo'),
        ];
        foreach ($paths as $path) {
            self::assertSame(403, $this->post($this->viewerId, $path, ['actual' => '1'])->getStatusCode(), $path);
        }
    }

    public function testThePaymentsExportHoldsEveryChargeInBothFormats(): void
    {
        $this->charges()->recordActual($this->owner, $this->firstCharge(), ['actual' => '63.20']);
        $this->signIn($this->viewerId);

        $csv = $this->app->handle((new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/payments/export.csv',
        ));
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString('payments-', $csv->getHeaderLine('Content-Disposition'));
        $lines = array_values(array_filter(explode("\n", (string) $csv->getBody())));
        self::assertSame('subscription,"due date",currency,expected,actual,amount,status,source,note', $lines[0]);
        self::assertCount(5, $lines);
        self::assertStringContainsString('Energy', (string) $csv->getBody());

        $json = $this->app->handle((new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/payments/export.json',
        ));
        $rows = json_decode((string) $json->getBody(), true);
        self::assertIsArray($rows);
        self::assertCount(4, $rows);
        self::assertContains('63.20', array_column($rows, 'actual'));
    }

    public function testAnInstanceAdminOutsideTheHouseholdIsRefusedTheExport(): void
    {
        $admin = (new UserRepository($this->db))
            ->create('admin@example.test', 'Admin', 'hash', true, new DateTimeImmutable());
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $admin);
        $this->session->remove(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID);

        foreach (['/payments/export.csv', '/payments/export.json'] as $path) {
            $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $path);
            $response = $this->app->handle($request);
            // No household role, so no sight of its subscriptions at all.
            self::assertSame(403, $response->getStatusCode(), $path);
            self::assertStringNotContainsString('Energy', (string) $response->getBody(), $path);
        }
    }

    protected function tearDown(): void
    {
        FakeUpload::cleanUp();
        ApiChargesTest::removeDirectory($this->attachmentDirectory);

        parent::tearDown();
    }

    public function testAnInvoiceUploadedFromAPaymentRowIsFiledAgainstIt(): void
    {
        $charge = $this->firstCharge();
        $this->signIn($this->ownerId);

        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'POST',
                'http://localhost/subscriptions/' . $this->subscriptionId . '/attachments',
                ['REMOTE_ADDR' => '127.0.0.1'],
            )
            ->withParsedBody([
                'charge_id' => (string) $charge,
                'return_to' => '/subscriptions/' . $this->subscriptionId . '/money#payments',
            ])
            ->withUploadedFiles(['file' => FakeUpload::pdf('bill.pdf')])
            ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container->get(CsrfTokenManager::class)->token());
        $response = $this->app->handle($request);

        $card = '/subscriptions/' . $this->subscriptionId . '/money#payments';
        self::assertSame($card, $response->getHeaderLine('Location'));
        self::assertSame(
            $charge,
            (int) $this->db->fetchValue(
                'SELECT charge_id FROM attachments WHERE subscription_id = :id',
                ['id' => $this->subscriptionId],
            ),
        );
        self::assertStringContainsString('bill.pdf', (string) $this->get($this->ownerId)->getBody());
    }

    public function testTheCsvExportDisarmsAFormulaAndTheJsonDoesNot(): void
    {
        $this->charges()->recordActual($this->owner, $this->firstCharge(), [
            'actual' => '1.00',
            'note' => '=HYPERLINK("http://x.example")',
        ]);
        $this->signIn($this->ownerId);

        $csv = (string) $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/payments/export.csv'),
        )->getBody();
        self::assertStringContainsString('"\'=HYPERLINK(""http://x.example"")"', $csv);

        $json = (string) $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/payments/export.json'),
        )->getBody();
        self::assertContains('=HYPERLINK("http://x.example")', array_column((array) json_decode($json, true), 'note'));
    }

    private function chargePath(int $charge, string $action): string
    {
        return '/subscriptions/' . $this->subscriptionId . '/charges/' . $charge . '/' . $action;
    }

    private function charges(): ChargeService
    {
        return $this->container->get(ChargeService::class);
    }

    private function firstCharge(): int
    {
        $charges = $this->charges()->forSubscription($this->owner, $this->subscriptionId);
        self::assertNotEmpty($charges);

        return $charges[0]->id;
    }

    /**
     * Two billing dates in the past, so the catch-up writes two charges.
     *
     * @return array<string, string>
     */
    private function input(string $name): array
    {
        return [
            'name' => $name,
            'price' => '58.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'weekly',
            'next_payment_date' => (new DateTimeImmutable('-10 days'))->format('Y-m-d'),
            'start_date' => (new DateTimeImmutable('-10 days'))->format('Y-m-d'),
        ];
    }

    private function get(int $userId): ResponseInterface
    {
        $this->signIn($userId);

        return $this->app->handle((new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/subscriptions/' . $this->subscriptionId . '/money',
        ));
    }

    /**
     * @param array<string, string> $body
     */
    private function post(int $userId, string $path, array $body = []): ResponseInterface
    {
        $this->signIn($userId);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody($body)
            ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container->get(CsrfTokenManager::class)->token());

        return $this->app->handle($request);
    }

    private function signIn(int $userId): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }
}
