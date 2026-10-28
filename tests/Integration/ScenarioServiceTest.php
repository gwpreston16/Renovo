<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Service\ScenarioService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Which of the scenario's columns are drawn "≈".
 *
 * A figure is an estimate only when a bill whose amount varies is in it. The
 * household, on 15 January 2026, in GBP: Energy, a varying £40 a month from
 * 1 February, and Gym, a fixed £10 a month from 5 February.
 */
final class ScenarioServiceTest extends DatabaseTestCase
{
    private const TODAY = '2026-01-15 09:00:00';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $userId;
    private int $householdId;
    private int $energyId;
    private int $gymId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            Clock::class => FrozenClock::at(self::TODAY),
        ]);

        $settings = $this->container()->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->markRatesAttempted(new DateTimeImmutable());

        $this->userId = (new UserRepository($this->db))
            ->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->householdId = (new HouseholdRepository($this->db))->create('House', $this->userId);
        (new MembershipRepository($this->db))->create($this->householdId, $this->userId, Role::OwnerAdmin);

        $this->energyId = $this->create('Energy', 4000, '2026-02-01', ['amount_varies' => true]);
        $this->gymId = $this->create('Gym', 1000, '2026-02-05');
    }

    public function testCancellingTheOnlyVaryingBillClearsTheMark(): void
    {
        $results = $this->results(['cancel' => [$this->energyId]]);

        // Now holds the varying bill; With changes holds only the fixed row;
        // the Difference is the bill's, so it is an estimate.
        self::assertTrue($results['estimate_current']);
        self::assertFalse($results['estimate_scenario']);
        self::assertTrue($results['estimate']);
        self::assertSame(1000, $results['run_rate']['scenario']['monthly']['combined']['amount_minor']);

        $html = $this->scenarioPage('cancel[]=' . $this->energyId);
        $row = $this->monthlyRow($html);

        self::assertMatchesRegularExpression('~<td class="numeric num">\s*≈ £50\.00\s*</td>~u', $row);
        self::assertMatchesRegularExpression('~<td class="numeric num">\s*£10\.00\s*</td>~u', $row);
        self::assertMatchesRegularExpression('~<td class="numeric num">\s*≈ £40\.00\s*</td>~u', $row);
    }

    public function testCancellingAFixedRowLeavesTheVaryingBillsMarkOnBothPictures(): void
    {
        $results = $this->results(['cancel' => [$this->gymId]]);

        self::assertTrue($results['estimate_current']);
        self::assertTrue($results['estimate_scenario']);
        self::assertTrue($results['estimate']);
    }

    public function testEmptyScenarioIsNotMarked(): void
    {
        // Cancel everything: nothing is left to be a varying bill.
        $results = $this->results(['cancel' => [$this->energyId, $this->gymId]]);

        self::assertFalse($results['estimate_scenario']);
        self::assertSame(0, $results['run_rate']['scenario']['monthly']['combined']['amount_minor']);

        $row = $this->monthlyRow($this->scenarioPage('cancel[]=' . $this->energyId . '&cancel[]=' . $this->gymId));
        self::assertMatchesRegularExpression('~<td class="numeric num">\s*£0\.00\s*</td>~u', $row);
        self::assertDoesNotMatchRegularExpression('~≈ £0\.00~u', $row);
    }

    /**
     * K87: a fixed € row beside the varying £ Energy, with no rate for euros,
     * so each column shows its subtotals. Only the £ one is drawn "≈".
     */
    public function testOnlyTheVaryingCurrencyIsMarked(): void
    {
        $this->create('Euro gym', 2000, '2026-02-07', ['currency' => 'EUR']);

        $results = $this->results(['cancel' => [$this->gymId]]);
        self::assertSame(['GBP'], $results['marks_current']['estimate_currencies']);
        self::assertSame(['GBP'], $results['marks_scenario']['estimate_currencies']);
        self::assertSame(['GBP'], $results['marks']['estimate_currencies']);

        $row = (string) preg_replace('/\s+/u', ' ', $this->monthlyRow($this->scenarioPage('cancel[]=' . $this->gymId)));
        self::assertStringContainsString('€20.00 · ≈ £50.00', $row);
        self::assertStringContainsString('€20.00 · ≈ £40.00', $row);
        self::assertStringNotContainsString('≈ €', $row);
        // The Difference: the gym's £10, in the currency that holds a varying
        // bill in either picture; the € part is exact.
        self::assertStringContainsString('€0.00 · ≈ £10.00', $row);
    }

    public function testWithNothingVaryingNothingIsMarked(): void
    {
        $this->db->execute('DELETE FROM subscriptions WHERE id = ' . $this->energyId);

        $results = $this->results(['cancel' => [$this->gymId]]);

        self::assertFalse($results['estimate_current']);
        self::assertFalse($results['estimate_scenario']);
        self::assertFalse($results['estimate']);
    }

    public function testCustomCycleChangeLeadsToTheEditForm(): void
    {
        $id = $this->create('Gadget', 1000, '2026-02-10', ['billing_cycle' => 'custom_days', 'cycle_days' => 30]);

        $row = $this->rowFor($id, ['price' => '10.00', 'cycle' => 'custom_days', 'days' => '14']);

        self::assertStringContainsString('cycle_days=14', $row['action_url']);
        self::assertStringContainsString('billing_cycle=custom_days', $row['action_url']);
        self::assertStringNotContainsString('scheduled_price', $row['action_url']);
        self::assertSame('subscription.update', $row['action_permission']);
    }

    public function testSameCyclePriceChangeStillLeadsToTheScheduleForm(): void
    {
        $id = $this->create('Gadget', 1000, '2026-02-10', ['billing_cycle' => 'custom_days', 'cycle_days' => 30]);

        $row = $this->rowFor($id, ['price' => '12.00', 'cycle' => 'custom_days', 'days' => '30']);

        self::assertStringContainsString('scheduled_price=12.00', $row['action_url']);
        self::assertStringNotContainsString('cycle_days', $row['action_url']);
        self::assertSame('price.manage', $row['action_permission']);
    }

    public function testATrialsCustomCycleChangeAfterConversionLeadsToTheEditForm(): void
    {
        $id = $this->create('Trial', 0, '2026-03-01', [
            'is_trial' => true,
            'trial_end_date' => '2026-03-01',
            'converts_to_price_minor' => 1000,
            'converts_to_billing_cycle' => 'custom_days',
            'converts_to_cycle_days' => 30,
        ]);

        $changed = $this->rowFor($id, ['price' => '10.00', 'cycle' => 'custom_days', 'days' => '14']);
        self::assertStringContainsString('converts_to_cycle_days=14', $changed['action_url']);
        self::assertSame('subscription.update', $changed['action_permission']);

        $same = $this->rowFor($id, ['price' => '12.00', 'cycle' => 'custom_days', 'days' => '30']);
        self::assertStringContainsString('scheduled_price=12.00', $same['action_url']);
        self::assertSame('price.manage', $same['action_permission']);
    }

    /**
     * @param array<string, string> $change
     * @return array<string, mixed>
     */
    private function rowFor(int $id, array $change): array
    {
        foreach ($this->results(['change' => [$id => $change]])['rows'] as $row) {
            if ($row['subscription']->id === $id) {
                return $row;
            }
        }

        self::fail('The scenario has no row for subscription ' . $id);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function results(array $query): array
    {
        return $this->container()->get(ScenarioService::class)->plan($this->scope(), $query)['results'];
    }

    private function scenarioPage(string $query): string
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        $response = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/forecast/scenario?' . $query),
        );
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** The "Monthly run-rate" row of the figures table. */
    private function monthlyRow(string $html): string
    {
        self::assertSame(1, preg_match('~<tr>\s*<th scope="row">[^<]*</th>(.*?)</tr>~s', $html, $row));

        return $row[1];
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->userId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function create(string $name, int $priceMinor, string $nextPayment, array $extra = []): int
    {
        return (new SubscriptionRepository($this->db))->create($this->scope(), $extra + [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $nextPayment,
            'anchor_day' => (int) (new DateTimeImmutable($nextPayment))->format('j'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);
    }

    private function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }
}
