<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AuditAction;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\Role;
use App\Persistence\PdoSessionHandler;
use App\Repository\AuditLogRepository;
use App\Repository\CategoryRepository;
use App\Repository\ChargeRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\InstanceSettingsRepository;
use App\Repository\MembershipRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SessionRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\ChargeSchedule;
use App\Service\ChargeService;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRate\FrankfurterProvider;
use App\Service\ExchangeRateService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SplitService;
use App\Service\SubscriptionService;
use App\Support\FrozenClock;
use App\Support\IsoDate;
use App\Tests\Support\FakeHttpClient;
use App\Tests\Support\TestKeys;
use App\Tests\Support\TestLogoFetcher;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;

/**
 * What PHP's default time zone does to the dates the application reads (Phase
 * 63, K68).
 *
 * The application's clock is UTC. On an install without Docker, whose PHP
 * `date.timezone` may be anything, a date read from the database is parsed in
 * that zone, so the same string is a different instant from the clock's. Each
 * test runs one class of site (`tests/fixtures/time-zone-sites.tsv`) under three
 * zones, a negative offset and two positive ones, and states what should hold:
 * the date read from the database is the instant the clock calls that day.
 *
 * Where it does not hold, the case is listed in {@see self::KNOWN_WRONG} against
 * the zones it fails in, and the test expects the failure, so the suite is
 * green and a fix (Phase 64) turns the expectation red until its mark is
 * removed. The measured results are in the *Findings* of PHASE-63.md.
 */
final class TimeZoneSweepTest extends DatabaseTestCase
{
    private const LOS_ANGELES = 'America/Los_Angeles';
    private const AUCKLAND = 'Pacific/Auckland';
    private const KOLKATA = 'Asia/Kolkata';
    private const NON_UTC = [self::LOS_ANGELES, self::AUCKLAND, self::KOLKATA];

    /**
     * UTC is the control: no case is ever marked wrong there, so a case that
     * fails for a reason other than the zone is not hidden by its mark.
     */
    private const ZONES = ['UTC', ...self::NON_UTC];

    /**
     * Case => the zones where it is wrong today (K68), so the test expects
     * the failure there. A date read from the database is midnight in PHP's
     * zone and the clock's midnight is UTC, so every non-UTC zone is off by its
     * own offset, and what that does depends on the comparison: a negative
     * offset reads a date as later than it is (a change dated today has not
     * started), a positive one as earlier (a contract ending today has ended).
     */
    private const KNOWN_WRONG = [
        'forecast-last-day' => [self::LOS_ANGELES],
        'change-dated-today' => [self::LOS_ANGELES],
        'contract-ends-today' => [self::AUCKLAND, self::KOLKATA],
        'session-listed' => [self::LOS_ANGELES],
        'date-column-instant' => self::NON_UTC,
        'form-date-instant' => self::NON_UTC,
        'timestamp-utc' => self::NON_UTC,
        'audit-entry-instant' => self::NON_UTC,
    ];

    private string $originalZone;
    private FrozenClock $clock;
    private SubscriptionRepository $subscriptions;
    private PriceHistoryRepository $historyRepository;
    private PriceHistoryService $priceHistory;
    private ForecastService $forecast;

    private int $alice;
    private int $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalZone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->household = $households->create('Shared house', $this->alice);
        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);

        // The application's clock is UTC whatever PHP's default zone is.
        $this->clock = FrozenClock::at('2026-09-14 10:00:00 UTC');
        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->historyRepository = new PriceHistoryRepository($this->db);
        $this->priceHistory = new PriceHistoryService(
            $this->historyRepository,
            $this->subscriptions,
            $this->db,
            $this->clock,
        );

        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db), TestKeys::ring());
        $settings->setBaseCurrency('GBP');

        $subscriptionService = new SubscriptionService(
            $this->subscriptions,
            new CategoryRepository($this->db),
            new PaymentMethodRepository($this->db),
            new TagRepository($this->db),
            TestLogoFetcher::silent($this->db, $this->clock),
            $memberships,
            $this->priceHistory,
            new ChargeService(
                new ChargeRepository($this->db),
                $this->subscriptions,
                $this->priceHistory,
                new ChargeSchedule(),
                $this->clock,
            ),
            $this->db,
            $this->clock,
        );

        $rates = new ExchangeRateService(
            new ExchangeRateRepository($this->db),
            new ExchangeRateProviderRegistry([
                new FrankfurterProvider(
                    FakeHttpClient::returningJson(['base' => 'GBP', 'date' => '2026-09-14', 'rates' => ['EUR' => 1.2]]),
                    new RequestFactory(),
                ),
            ]),
            $settings,
            $this->clock,
            new NullLogger(),
            43200,
            3600,
            '',
        );
        $rates->refresh();

        $this->forecast = new ForecastService(
            $subscriptionService,
            $this->historyRepository,
            new SplitService(new SplitRepository($this->db), $this->subscriptions, $memberships, $this->db),
            $rates,
            $settings,
            $this->clock,
        );
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalZone);
        parent::tearDown();
    }

    /**
     * Class: a date-only column, read and compared with the clock.
     *
     * K68's own case: a price change scheduled for the last day of a
     * three-month forecast is left out.
     */
    #[DataProvider('zones')]
    public function testForecastKeepsALastDayPriceChange(string $zone): void
    {
        $id = $this->subscriptionWithChange('2026-12-14');

        $this->inZone($zone, 'forecast-last-day', function () use ($id): void {
            $rows = $this->forecast->scheduledChanges($this->scope(), [$id => $this->subscription($id)], 3);

            self::assertCount(1, $rows, 'the change on the last day of the horizon is listed');
        });
    }

    /**
     * K68 as the log words it, with its zone.
     */
    public function testForecastKeepsALastDayPriceChangeInLosAngeles(): void
    {
        $this->testForecastKeepsALastDayPriceChange(self::LOS_ANGELES);
    }

    /**
     * Class: a date-only column. A price change dated today is in force today.
     */
    #[DataProvider('zones')]
    public function testAPriceChangeDatedTodayHasTakenEffect(string $zone): void
    {
        $id = $this->subscriptionWithChange('2026-09-14');

        $this->inZone($zone, 'change-dated-today', function () use ($id): void {
            $changes = $this->historyRepository->findAllBySubscription($this->scope())[$id];
            $change = $changes[array_key_last($changes)];

            self::assertTrue($change->hasTakenEffectOn($this->clock->today()));
        });
    }

    /**
     * Class: a date-only column. A date read from the database is the instant
     * the clock calls that day.
     */
    #[DataProvider('zones')]
    public function testDateOnlyColumnsAreReadAsTheSameDayInEveryZone(string $zone): void
    {
        $id = $this->subscriptionWithChange('2026-09-14');

        $this->inZone($zone, 'date-column-instant', function () use ($id): void {
            $changes = $this->historyRepository->findAllBySubscription($this->scope())[$id];
            $change = $changes[array_key_last($changes)];

            self::assertSame('2026-09-14', $change->effectiveFrom->format('Y-m-d'), 'the day is the day');
            self::assertSame(
                $this->clock->today()->getTimestamp(),
                $change->effectiveFrom->getTimestamp(),
                'and it is the clock\'s midnight',
            );
        });
    }

    /**
     * Class: a date-only column, where a positive offset goes wrong the other
     * way: midnight is earlier than the clock's, so a date that is today reads
     * as past. A contract that ends today has not ended.
     */
    #[DataProvider('zones')]
    public function testAContractEndingTodayIsNotYetOutOfContract(string $zone): void
    {
        $id = $this->subscriptions->create($this->scope(), [
            'name' => 'Broadband',
            'price_minor' => 2999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-09-20',
            'anchor_day' => 20,
            'contract_ends_on' => '2026-09-14',
            'is_active' => true,
        ], []);

        $this->inZone($zone, 'contract-ends-today', function () use ($id): void {
            self::assertFalse($this->subscription($id)->isOutOfContract($this->clock->today()));
        });
    }

    /**
     * Class: a date-only value typed into a form, parsed with `!Y-m-d`.
     */
    #[DataProvider('zones')]
    public function testADateTypedIntoAFormIsTheClocksMidnight(string $zone): void
    {
        $this->inZone($zone, 'form-date-instant', function (): void {
            $parsed = IsoDate::parse('2026-09-14');

            self::assertNotNull($parsed);
            self::assertSame($this->clock->today()->getTimestamp(), $parsed->getTimestamp());
        });
    }

    /**
     * Class: a timestamp column the application wrote in UTC.
     */
    #[DataProvider('zones')]
    public function testTimestampColumnsAreReadAsUtc(string $zone): void
    {
        $id = $this->subscriptionWithChange('2026-12-14');
        $this->db->execute(
            'UPDATE ' . $this->db->platform()->quoteIdentifier('subscriptions')
            . ' SET ' . $this->db->platform()->quoteIdentifier('created_at') . ' = :at WHERE id = :id',
            ['at' => '2026-09-14 10:00:00', 'id' => $id],
        );
        $utc = new DateTimeImmutable('2026-09-14 10:00:00', new DateTimeZone('UTC'));

        $this->inZone($zone, 'timestamp-utc', function () use ($id, $utc): void {
            self::assertSame($utc->getTimestamp(), $this->subscription($id)->createdAt->getTimestamp());
        });
    }

    /**
     * Class: a time a person is shown, written from the UTC clock.
     */
    #[DataProvider('zones')]
    public function testUserFacingTimesAreShownInTheirOwnZone(string $zone): void
    {
        $audit = new AuditLogRepository($this->db);
        $audit->append(
            AuditAction::LoginSucceeded,
            $this->clock->now(),
            $this->alice,
            'Alice',
            null,
            'alice@example.test',
            $this->household,
            null,
            null,
            [],
        );

        $this->inZone($zone, 'audit-entry-instant', function () use ($audit): void {
            $entries = $audit->findForHousehold($this->scope());

            self::assertCount(1, $entries);
            self::assertSame($this->clock->now()->getTimestamp(), $entries[0]->occurredAt->getTimestamp());
        });
    }

    /**
     * Class: a session row, which is written with `date()` and listed against
     * the clock, so the two are in different zones when the default is not UTC.
     */
    #[DataProvider('zones')]
    public function testTheCurrentSessionIsListedAsActive(string $zone): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sessions = new SessionRepository($this->db);

        $this->inZone($zone, 'session-listed', function () use ($sessions, $zone, $now): void {
            $id = 'tz' . bin2hex(random_bytes(12));
            $handler = new PdoSessionHandler($this->db, 86400 * 30, 3600);
            $handler->associateUser($this->alice);
            $handler->setPersistent(false);
            $handler->write($id, 'data');

            $listed = $sessions->findActiveForUser($this->alice, $id, $now);
            $handler->destroy($id);

            self::assertCount(1, $listed, $zone . ': a session that ends in an hour is still active');
        });
    }

    /**
     * Class: parsed and formatted inside one zone, so the zone cancels out.
     */
    #[DataProvider('zones')]
    public function testAMonthLabelIsTheSameInEveryZone(string $zone): void
    {
        $this->inZone($zone, 'month-label', function (): void {
            $month = new DateTimeImmutable('2026-12' . '-01');

            self::assertSame('2026-12-01', $month->format('Y-m-d'));
            self::assertSame('2026-12', $month->format('Y-m'));
            self::assertSame('2027-01', $month->modify('+1 month')->format('Y-m'));
        });
    }

    /**
     * @return array<string, array{string}>
     */
    public static function zones(): array
    {
        return array_combine(self::ZONES, array_map(static fn (string $zone): array => [$zone], self::ZONES));
    }

    /**
     * Run an assertion with PHP's default zone set, and restore it.
     *
     * A case listed in KNOWN_WRONG for the zone must fail there: if it passes,
     * the mark is out of date and the test says so.
     *
     * @param callable(): void $assertion
     */
    private function inZone(string $zone, string $case, callable $assertion): void
    {
        date_default_timezone_set($zone);
        try {
            if (in_array($zone, self::KNOWN_WRONG[$case] ?? [], true)) {
                try {
                    $assertion();
                } catch (ExpectationFailedException) {
                    self::addToAssertionCount(1);

                    return;
                }
                self::fail(sprintf('%s now holds in %s: remove it from KNOWN_WRONG (K68 is fixed).', $case, $zone));
            }

            $assertion();
        } finally {
            date_default_timezone_set('UTC');
        }
    }

    private function subscriptionWithChange(string $effectiveFrom): int
    {
        $scope = $this->scope();
        $id = $this->subscriptions->create($scope, [
            'name' => 'Streaming',
            'price_minor' => 599,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-09-20',
            'anchor_day' => 20,
            'is_active' => true,
        ], []);
        $this->priceHistory->recordInitialPrice(
            $scope,
            $id,
            Money::of(599, 'GBP'),
            new DateTimeImmutable('2025-01-01'),
            $this->alice,
        );
        $this->historyRepository->append(
            $scope,
            $id,
            Money::of(1199, 'GBP'),
            new DateTimeImmutable($effectiveFrom),
            \App\Domain\PriceChangeSource::Scheduled,
            null,
            $this->alice,
        );

        return $id;
    }

    private function subscription(int $id): \App\Domain\Entity\Subscription
    {
        $subscription = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($subscription);

        return $subscription;
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Shared);
    }
}
