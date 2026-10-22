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
use App\Service\CatchUpService;
use App\Service\ChargeService;
use App\Service\InstanceSettingsService;
use App\Service\SubscriptionService;
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
    private Scope $owner;

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
