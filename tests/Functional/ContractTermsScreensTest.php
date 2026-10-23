<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\ChargeSource;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\Role;
use App\Repository\ChargeRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Phase 35's screens: a contract's end, a fixed term's progress and a varying
 * bill's estimate, as the pages draw them, and the Payments card's forms.
 */
final class ContractTermsScreensTest extends DatabaseTestCase
{
    private const TODAY = '2026-10-15 09:00:00';

    /** @var App<\Psr\Container\ContainerInterface|null> */
    private App $app;
    private ArraySession $session;
    private SubscriptionRepository $subscriptions;
    private Scope $owner;

    private int $ownerId;
    private int $viewerId;
    private int $householdId;
    private DateTimeImmutable $nextMonth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            // Mid-month, so "+3 days" is still this month's and every date
            // below means the same thing whatever day the suite runs.
            Clock::class => FrozenClock::at(self::TODAY),
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());
        $this->householdId = (new HouseholdRepository($this->db))->create('Home', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $this->owner = Scope::forMember(
            $this->ownerId,
            false,
            $this->householdId,
            Role::OwnerAdmin,
            IsolationMode::Shared,
        );
        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->nextMonth = $this->day('first day of next month');
    }

    public function testAFixedTermShowsItsProgress(): void
    {
        $start = $this->nextMonth->modify('-14 months');
        $id = $this->create('Sofa', 7700, [
            'start_date' => $start->format('Y-m-d'),
            'payments_end_on' => $start->modify('+35 months')->format('Y-m-d'),
        ]);

        $body = $this->page('/subscriptions/' . $id . '/money', $this->ownerId);

        self::assertStringContainsString('Payment 14 of 36', $body);
        self::assertStringContainsString('£1,078.00 of £2,772.00 paid', $body);
    }

    public function testAVaryingBillOffersItsAverageAsTheEstimate(): void
    {
        $id = $this->varyingBill();

        $owner = $this->page('/subscriptions/' . $id . '/money', $this->ownerId);
        self::assertStringContainsString('The last 3 bills averaged £63.00, against an estimate of £50.00.', $owner);
        self::assertStringContainsString('action="/subscriptions/' . $id . '/estimate"', $owner);
        self::assertStringContainsString('estimate £50.00', $owner);

        // A Viewer reads the average but is offered nothing to press.
        $viewer = $this->page('/subscriptions/' . $id . '/money', $this->viewerId);
        self::assertStringContainsString('averaged £63.00', $viewer);
        self::assertStringNotContainsString('/estimate"', $viewer);

        $this->signIn($this->ownerId);
        $response = $this->request('POST', '/subscriptions/' . $id . '/estimate');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(6300, $this->subscriptions->find($this->owner, $id)?->price->amountMinor);

        $this->signIn($this->viewerId);
        self::assertSame(403, $this->request('POST', '/subscriptions/' . $id . '/estimate')->getStatusCode());
    }

    public function testAVaryingBillIsAnEstimateInTheList(): void
    {
        $this->create('Octopus Energy', 5800, ['amount_varies' => true]);
        $this->create('Netflix', 1099);

        $body = $this->page('/subscriptions', $this->ownerId);

        self::assertStringContainsString('≈ £58.00', $body);
        self::assertStringNotContainsString('≈ £10.99', $body);
        // The list's total includes the estimate, so it is one too.
        self::assertMatchesRegularExpression('~id="list-summary".*?≈ £68\\.99~s', $body);
    }

    public function testForwardLookingTotalsIncludingAVaryingBillAreEstimates(): void
    {
        $this->create('Octopus Energy', 5800, [
            'amount_varies' => true,
            'next_payment_date' => $this->day('+3 days')->format('Y-m-d'),
            'anchor_day' => (int) $this->day('+3 days')->format('j'),
        ]);
        $this->signIn($this->ownerId);
        $this->request('POST', '/budgets', [
            'name' => 'Monthly',
            'period' => 'monthly',
            'amount' => '500.00',
            'currency' => 'GBP',
        ]);

        self::assertMatchesRegularExpression('~≈ £[0-9,.]+~', $this->page('/forecast', $this->ownerId));
        self::assertStringContainsString('≈ £58.00', $this->page('/', $this->ownerId));
        self::assertMatchesRegularExpression(
            '~class="budget-projected num">≈ £~',
            $this->page('/budgets', $this->ownerId),
        );
    }

    public function testTheCalendarAndThePlannerMarkAVaryingBill(): void
    {
        $id = $this->create('Octopus Energy', 5800, ['amount_varies' => true]);
        $month = $this->nextMonth->format('Y-m');

        $calendar = $this->page('/calendar?month=' . $month, $this->ownerId);
        self::assertStringContainsString('≈ £58.00', $calendar);

        $scenario = $this->page('/forecast/scenario?cancel[]=' . $id, $this->ownerId);
        self::assertMatchesRegularExpression('~id="scenario-results".*?≈ £~s', $scenario);
    }

    public function testFixedRowsOnlyAreNotEstimatesAnywhere(): void
    {
        $this->create('Netflix', 1099, [
            'next_payment_date' => $this->day('+3 days')->format('Y-m-d'),
        ]);

        foreach (['/', '/forecast', '/subscriptions', '/calendar'] as $path) {
            self::assertStringNotContainsString('≈ £', $this->page($path, $this->ownerId), $path);
        }
    }

    public function testUseAsEstimateWithNothingToUseChangesNothing(): void
    {
        $id = $this->create('Octopus Energy', 5000, ['amount_varies' => true]);
        $this->signIn($this->ownerId);

        $response = $this->request('POST', '/subscriptions/' . $id . '/estimate');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(5000, $this->subscriptions->find($this->owner, $id)?->price->amountMinor);
        self::assertSame(0, (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM subscription_price_history WHERE subscription_id = :id',
            ['id' => $id],
        ));
    }

    public function testATotalOfFixedRowsOnlyIsNotMarked(): void
    {
        $this->create('Netflix', 1099);

        $body = $this->page('/subscriptions', $this->ownerId);

        self::assertStringNotContainsString('≈ £10.99', $body);
    }

    public function testAContractEndIsOnTheCancelByListAndAnEndedOneIsAnInsight(): void
    {
        $this->create('Sky Broadband', 3500, [
            'contract_ends_on' => $this->day('-10 days')->format('Y-m-d'),
            'start_date' => $this->day('-2 years')->format('Y-m-d'),
        ]);

        $cancellations = $this->page('/cancellations', $this->ownerId);
        self::assertStringContainsString('Sky Broadband', $cancellations);
        self::assertStringContainsString('Contract ended', $cancellations);

        $stats = $this->page('/stats', $this->ownerId);
        self::assertStringContainsString('Out of contract', $stats);
        self::assertStringContainsString('has been out of contract since', $stats);
    }

    public function testARefusedPaymentReopensWithWhatWasTyped(): void
    {
        $id = $this->create('Netflix', 1099);
        $this->signIn($this->ownerId);

        // A future date is refused.
        $response = $this->request('POST', '/subscriptions/' . $id . '/charges', [
            'date' => $this->day('+5 days')->format('Y-m-d'),
            'amount' => '12.34',
            'note' => 'typed by hand',
        ]);
        self::assertSame(302, $response->getStatusCode());

        $body = (string) $this->request('GET', '/subscriptions/' . $id . '/money')->getBody();
        self::assertMatchesRegularExpression(
            '~<details class="settings-disclosure" open>\\s*<summary[^>]*>.*?Add a payment~s',
            $body,
        );
        self::assertStringContainsString('value="12.34"', $body);
        self::assertStringContainsString('value="typed by hand"', $body);

        // Read once: the next visit has the form closed and empty.
        $again = (string) $this->request('GET', '/subscriptions/' . $id . '/money')->getBody();
        self::assertStringNotContainsString('value="typed by hand"', $again);
    }

    public function testARefusedAmountReopensItsChargesForm(): void
    {
        $id = $this->create('Netflix', 1099);
        $chargeId = (new ChargeRepository($this->db))->record(
            $this->owner,
            $id,
            $this->ownerId,
            $this->day('-3 days'),
            Money::of(1099, 'GBP'),
            ChargeSource::CatchUp,
        );
        $this->signIn($this->ownerId);

        $this->request('POST', '/subscriptions/' . $id . '/charges/' . $chargeId . '/actual', [
            'actual' => 'not money',
            'note' => 'kept',
        ]);
        $body = (string) $this->request('GET', '/subscriptions/' . $id . '/money')->getBody();

        self::assertStringContainsString('class="settings-disclosure payments-actions" open', $body);
        self::assertStringContainsString('value="not money"', $body);
        // The invoice field says what may be uploaded, as the other one does (K79).
        self::assertStringContainsString('aria-describedby="invoice-' . $chargeId . '-hint"', $body);
    }

    public function testTheFormSavesTheTermsAndShowsThemBack(): void
    {
        $new = $this->page('/subscriptions/new', $this->ownerId);
        self::assertStringContainsString('name="contract_ends_on"', $new);
        self::assertStringContainsString('name="payments_end_on"', $new);
        self::assertStringContainsString('name="amount_varies"', $new);
        self::assertStringContainsString('Estimated amount', $new);
        // Collapsed for a row that uses none of them.
        self::assertStringContainsString('<details class="more-details terms-details" id="terms" >', $new);

        $response = $this->request('POST', '/subscriptions', [
            'name' => 'Octopus Energy',
            'price' => '58.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $this->nextMonth->format('Y-m-d'),
            'contract_ends_on' => $this->nextMonth->modify('+1 year')->format('Y-m-d'),
            'payments_end_on' => '',
            'amount_varies' => '1',
        ]);
        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());

        $saved = $this->subscriptions->findAllForStats($this->owner, false)[0];
        self::assertTrue($saved->amountVaries);
        self::assertSame(
            $this->nextMonth->modify('+1 year')->format('Y-m-d'),
            $saved->contractEndsOn?->format('Y-m-d'),
        );
        self::assertNull($saved->paymentsEndOn);

        $edit = $this->page('/subscriptions/' . $saved->id . '/edit', $this->ownerId);
        self::assertStringContainsString('<details class="more-details terms-details" id="terms" open>', $edit);
        self::assertMatchesRegularExpression('~id="amount_varies" name="amount_varies" value="1"\s+checked~', $edit);
    }

    public function testALastPaymentBeforeTheNextOneIsAFormError(): void
    {
        $this->signIn($this->ownerId);
        $response = $this->request('POST', '/subscriptions', [
            'name' => 'Phone',
            'price' => '30.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $this->nextMonth->format('Y-m-d'),
            'payments_end_on' => $this->nextMonth->modify('-1 day')->format('Y-m-d'),
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('The last payment can’t come before the next one.', $body);
        self::assertStringContainsString('id="terms" open', $body);
    }

    public function testTheContractAlertCanBeTurnedOff(): void
    {
        $body = $this->page('/settings/notifications', $this->ownerId);
        self::assertStringContainsString('name="contract_alerts"', $body);

        $response = $this->request('POST', '/settings/notifications/preferences', [
            'lead_days' => ['30', '7', '1'],
            'digest_mode' => 'immediate',
            'digest_day' => '1',
            'budget_alerts' => '1',
            'price_change_alerts' => '1',
            'contract_alerts' => '0',
        ]);
        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        self::assertFalse(
            $container->get(NotificationPreferenceRepository::class)->findForUser($this->ownerId)->contractAlerts,
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function create(string $name, int $price, array $extra = []): int
    {
        return $this->subscriptions->create($this->owner, $extra + [
            'name' => $name,
            'price_minor' => $price,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $this->nextMonth->format('Y-m-d'),
            'anchor_day' => 1,
            'is_active' => true,
            'owner_user_id' => $this->ownerId,
        ], []);
    }

    private function varyingBill(): int
    {
        $id = $this->create('Octopus Energy', 5000, ['amount_varies' => true]);
        $charges = new ChargeRepository($this->db);
        foreach ([6000, 6600, 6300] as $months => $paid) {
            $charges->record(
                $this->owner,
                $id,
                $this->ownerId,
                $this->nextMonth->modify(sprintf('-%d months', $months + 1)),
                Money::of(5000, 'GBP'),
                ChargeSource::CatchUp,
                $paid,
            );
        }

        return $id;
    }

    /**
     * A day relative to the frozen today, at midnight.
     */
    private function day(string $relative): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::TODAY))->modify($relative)->setTime(0, 0);
    }

    private function page(string $path, int $userId): string
    {
        $this->signIn($userId);
        $response = $this->request('GET', $path);
        self::assertSame(200, $response->getStatusCode(), $path . ' did not render');

        return (string) $response->getBody();
    }

    private function signIn(int $userId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($method !== 'GET') {
            $container = $this->app->getContainer();
            self::assertNotNull($container);
            $request = $request->withParsedBody($body)->withHeader(
                CsrfTokenManager::HEADER_NAME,
                $container->get(CsrfTokenManager::class)->token(),
            );
        }

        return $this->app->handle($request);
    }
}
