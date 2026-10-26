<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\PriceHistoryService;
use App\Service\SubscriptionService;
use App\Service\ValidationException;
use App\Support\Clock;
use App\Support\FrozenClock;
use DateTimeImmutable;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

/**
 * The largest price an entry path accepts (K9), and a price edited before a
 * subscription starts (K10). Both go through `SubscriptionService`, the one
 * path the web form, the API, the CSV import and a backup restore share, so
 * the service is where each is pinned.
 */
final class PriceCeilingTest extends DatabaseTestCase
{
    private SubscriptionService $subscriptions;
    private PriceHistoryService $history;
    private SubscriptionRepository $rows;
    private FrozenClock $clock;
    private Scope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-10-22 09:00:00');

        $container = $this->container();
        $this->subscriptions = $container->get(SubscriptionService::class);
        $this->history = $container->get(PriceHistoryService::class);
        $this->rows = $container->get(SubscriptionRepository::class);

        $users = new UserRepository($this->db);
        $userId = $users->create('owner@example.test', 'Owner', 'hash', false, $this->clock->now());
        $householdId = (new HouseholdRepository($this->db))->create('Household', $userId);
        (new MembershipRepository($this->db))->create($householdId, $userId, Role::OwnerAdmin);

        $this->scope = Scope::forMember($userId, false, $householdId, Role::OwnerAdmin, IsolationMode::Shared);
    }

    private function container(): ContainerInterface
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $settings['database'] = [
            'driver' => $this->env('DB_DRIVER', 'pgsql'),
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ];

        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/config/container.php')($builder, $settings);
        $builder->addDefinitions([Clock::class => $this->clock]);

        return $builder->build();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Streaming',
            'price' => '10.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'start_date' => '',
            'is_active' => '1',
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tooLarge(): array
    {
        return [
            'far past an integer' => ['99999999999999999999'],
            'one minor unit over' => ['10000000000.01'],
            'negative, one over' => ['-10000000000.01'],
        ];
    }

    /**
     * @dataProvider tooLarge
     */
    public function testAPriceAboveTheCeilingIsRefused(string $price): void
    {
        $errors = $this->subscriptions->validationErrors($this->scope, $this->input(['price' => $price]));

        self::assertSame('error.price.too_large', $errors['price']->key);
    }

    public function testAConvertsToPriceAboveTheCeilingIsRefused(): void
    {
        $errors = $this->subscriptions->validationErrors($this->scope, $this->input([
            'is_trial' => '1',
            'trial_end_date' => '2026-11-01',
            'converts_to_price' => '99999999999999999999',
        ]));

        self::assertSame('error.price.too_large', $errors['converts_to_price']->key);
    }

    public function testACreateAboveTheCeilingStoresNothing(): void
    {
        try {
            $this->subscriptions->create($this->scope, $this->input(['price' => '99999999999999999999']));
            self::fail('A price past the ceiling must not be saved.');
        } catch (ValidationException) {
            self::assertCount(0, $this->rows->findAllForStats($this->scope, false));
        }
    }

    public function testAPriceAtTheCeilingSavesAndTheHouseholdTotalsWithoutError(): void
    {
        $ceiling = Money::of(Money::MAX_MINOR, 'GBP')->toDecimalString();

        foreach (['monthly', 'weekly', 'yearly'] as $cycle) {
            $this->subscriptions->create($this->scope, $this->input(['price' => $ceiling, 'billing_cycle' => $cycle]));
        }

        $monthly = 0;
        $yearly = 0;
        foreach ($this->rows->findAllForStats($this->scope, false) as $subscription) {
            self::assertSame(Money::MAX_MINOR, $subscription->price->amountMinor);
            $monthly += $subscription->monthlyMinor() ?? 0;
            $yearly += $subscription->yearlyMinor() ?? 0;
        }

        self::assertGreaterThan(0, $monthly);
        self::assertGreaterThan(0, $yearly);
    }

    public function testAPriceEditedBeforeTheStartDateSurvivesIt(): void
    {
        $id = $this->subscriptions->create($this->scope, $this->input([
            'price' => '10.00',
            'start_date' => '2026-12-01',
            'next_payment_date' => '2026-12-01',
        ]));

        $this->subscriptions->update($this->scope, $id, $this->input([
            'price' => '12.00',
            'start_date' => '2026-12-01',
            'next_payment_date' => '2026-12-01',
        ]));
        self::assertSame(1200, $this->rows->find($this->scope, $id)?->price->amountMinor);

        $this->clock->advanceTo(new DateTimeImmutable('2026-12-02 09:00:00'));
        $this->history->applyDueChanges($this->scope);

        self::assertSame(1200, $this->rows->find($this->scope, $id)?->price->amountMinor);
    }

    public function testAnEditAfterTheStartDateIsStillDatedToday(): void
    {
        $id = $this->subscriptions->create($this->scope, $this->input([
            'price' => '10.00',
            'start_date' => '2026-01-01',
        ]));

        $this->subscriptions->update($this->scope, $id, $this->input([
            'price' => '12.00',
            'start_date' => '2026-01-01',
        ]));

        $rows = $this->container()->get(\App\Repository\PriceHistoryRepository::class)
            ->findForSubscription($this->scope, $id);
        $dates = array_map(static fn ($row): string => $row->effectiveFrom->format('Y-m-d'), $rows);

        self::assertContains('2026-10-22', $dates);
    }
}
