<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\ChargeService;
use App\Service\ChargeSchedule;
use App\Repository\ChargeRepository;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Notification\NotifierRegistry;
use App\Repository\BudgetAlertStateRepository;
use App\Repository\BudgetRepository;
use App\Repository\CategoryRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\InstanceSettingsRepository;
use App\Repository\MembershipRepository;
use App\Repository\NotificationChannelRepository;
use App\Repository\NotificationLogRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\NotificationRouteRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\ScopeFactory;
use App\Service\BudgetService;
use App\Service\CatchUpService;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRate\FrankfurterProvider;
use App\Service\ExchangeRateService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\Notification\AlertScanner;
use App\Service\Notification\DigestBuilder;
use App\Service\Notification\PriceChangeScanner;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\NotificationRateLimiter;
use App\Service\Notification\NotificationSettingsService;
use App\Service\Notification\ReminderRunner;
use App\Service\PriceHistoryService;
use App\Service\SplitService;
use App\Service\SubscriptionService;
use App\Service\TrialService;
use App\Support\FrozenClock;
use App\I18n\CatalogLoader;
use App\I18n\LocaleContext;
use App\I18n\Locales;
use App\Support\MoneyFormatter;
use App\Tests\Support\FakeHttpClient;
use App\Tests\Support\TestLogoFetcher;
use App\Tests\Support\RecordingNotifier;
use App\Tests\Support\TestTranslator;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use App\Tests\Support\TestFixedTermService;

/**
 * The whole notification stack, wired by hand against a real database.
 *
 * Built once here rather than in each test because the interesting behaviour —
 * idempotency, routing, the budget state machine, digests — only shows up with
 * the real repositories and the real unique index underneath. A mock of the
 * ledger would agree with whatever the test expected.
 *
 * The clock is frozen so that "seven days before" is a fact rather than a
 * function of when the suite runs.
 */
abstract class NotificationTestCase extends DatabaseTestCase
{
    protected FrozenClock $clock;
    protected RecordingNotifier $notifier;
    protected NotifierRegistry $registry;

    protected NotificationSettingsService $notificationSettings;
    protected NotificationDispatcher $dispatcher;
    protected NotificationLogRepository $log;
    protected NotificationChannelRepository $channels;
    protected AlertScanner $scanner;
    protected ReminderRunner $runner;
    protected SubscriptionRepository $subscriptions;
    protected SubscriptionService $subscriptionService;
    protected BudgetService $budgets;
    protected UserRepository $users;
    protected MembershipRepository $memberships;
    protected HouseholdRepository $households;
    protected InstanceSettingsService $instanceSettings;

    protected int $alice;
    protected int $bob;
    protected int $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-09-15 08:00:00');

        $this->users = new UserRepository($this->db);
        $this->households = new HouseholdRepository($this->db);
        $this->memberships = new MembershipRepository($this->db);

        $this->alice = $this->users->create('alice@example.test', 'Alice', 'hash');
        $this->bob = $this->users->create('bob@example.test', 'Bob', 'hash');
        $this->household = $this->households->create('Shared house', $this->alice);
        $this->memberships->create($this->household, $this->alice, Role::OwnerAdmin);
        $this->memberships->create($this->household, $this->bob, Role::Editor);

        $this->subscriptions = new SubscriptionRepository($this->db);
        $categories = new CategoryRepository($this->db);
        $this->instanceSettings = new InstanceSettingsService(new InstanceSettingsRepository($this->db));
        $this->instanceSettings->setBaseCurrency('GBP');

        $historyRepository = new PriceHistoryRepository($this->db);
        $priceHistory = new PriceHistoryService($historyRepository, $this->subscriptions, $this->db, $this->clock);

        $this->subscriptionService = new SubscriptionService(
            $this->subscriptions,
            $categories,
            new PaymentMethodRepository($this->db),
            new TagRepository($this->db),
            TestLogoFetcher::silent($this->db, $this->clock),
            $this->memberships,
            $priceHistory,
            new ChargeService(
                new ChargeRepository($this->db),
                $this->subscriptions,
                $priceHistory,
                new ChargeSchedule(),
                $this->clock,
            ),
            $this->db,
            $this->clock,
        );

        $splits = new SplitService(
            new SplitRepository($this->db),
            $this->subscriptions,
            $this->memberships,
            $this->db,
        );

        $rates = new ExchangeRateService(
            new ExchangeRateRepository($this->db),
            new ExchangeRateProviderRegistry([
                new FrankfurterProvider(
                    FakeHttpClient::returningJson([
                        'base' => 'GBP',
                        'date' => '2026-09-14',
                        'rates' => ['EUR' => 1.2],
                    ]),
                    new RequestFactory(),
                ),
            ]),
            $this->instanceSettings,
            $this->clock,
            new NullLogger(),
            43200,
            3600,
            '',
        );
        $rates->refresh();

        $forecast = new ForecastService(
            $this->subscriptionService,
            $historyRepository,
            $splits,
            $rates,
            $this->instanceSettings,
            $this->clock,
        );

        $this->budgets = new BudgetService(
            new BudgetRepository($this->db),
            $categories,
            $this->memberships,
            $forecast,
            $rates,
            $this->instanceSettings,
            $this->clock,
        );

        $this->notifier = new RecordingNotifier();
        $this->registry = new NotifierRegistry([$this->notifier]);

        $this->channels = new NotificationChannelRepository($this->db, $this->clock);
        $this->log = new NotificationLogRepository($this->db, $this->clock);

        $this->notificationSettings = new NotificationSettingsService(
            $this->channels,
            new NotificationPreferenceRepository($this->db, $this->clock),
            new NotificationRouteRepository($this->db, $this->clock),
            $this->registry,
        );

        $this->dispatcher = new NotificationDispatcher(
            $this->notificationSettings,
            $this->registry,
            $this->log,
            $this->channels,
            new NotificationRateLimiter($this->log, $this->clock, 60, 20),
            new NullLogger(),
            $this->clock,
            TestTranslator::create(),
        );

        $this->scanner = new AlertScanner(
            $this->subscriptionService,
            $this->budgets,
            new BudgetAlertStateRepository($this->db),
            new MoneyFormatter(new LocaleContext('en_GB')),
            TestTranslator::create(),
            $this->clock,
            'https://renovo.example',
        );

        $this->runner = new ReminderRunner(
            $this->users,
            new LocaleContext('en_GB'),
            new Locales(new CatalogLoader(dirname(__DIR__, 2) . '/translations'), 'en_GB'),
            $this->memberships,
            new ScopeFactory($this->memberships, $this->instanceSettings),
            new CatchUpService(
                $priceHistory,
                new TrialService($this->subscriptions, $priceHistory, $this->db, $this->clock),
                $this->subscriptionService,
                TestFixedTermService::build($this->db, $this->clock, $priceHistory),
            ),
            $this->scanner,
            new PriceChangeScanner(
                $this->subscriptionService,
                $priceHistory,
                new MoneyFormatter(new LocaleContext('en_GB')),
                TestTranslator::create(),
                'https://renovo.example',
            ),
            $this->log,
            new DigestBuilder(TestTranslator::create()),
            $this->dispatcher,
            $this->notificationSettings,
            $this->clock,
            new NullLogger(),
            'https://renovo.example',
        );
    }

    protected function addChannel(int $userId, string $label = 'Phone'): int
    {
        return $this->channels->create($userId, $this->notifier->key(), $label, ['target' => $label]);
    }

    protected function scope(int $userId, IsolationMode $mode = IsolationMode::Shared, ?int $household = null): Scope
    {
        return Scope::forMember($userId, false, $household ?? $this->household, Role::OwnerAdmin, $mode);
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function createSubscription(
        string $name,
        int $priceMinor,
        ?string $nextPayment,
        int $ownerUserId,
        array $extra = [],
    ): int {
        return $this->subscriptions->create($this->scope($ownerUserId), array_merge([
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $nextPayment,
            'anchor_day' => $nextPayment === null
                ? null
                : (int) (new DateTimeImmutable($nextPayment))->format('j'),
            'is_active' => true,
            'owner_user_id' => $ownerUserId,
        ], $extra), []);
    }

    protected function user(int $id): \App\Domain\Entity\User
    {
        $user = $this->users->findById($id);
        self::assertNotNull($user);

        return $user;
    }
}
