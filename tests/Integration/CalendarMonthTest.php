<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\NoticePeriod;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Domain\WeekStart;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\CalendarService;
use App\Service\InstanceSettingsService;
use App\Service\SplitService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The rules the calendar's month is built by: which deadlines belong on it,
 * what a deadline costs, the order a day's items are drawn in, and what is
 * counted.
 *
 * They are asserted on the service rather than the rendered page because each
 * is a rule about *which* rows belong where, and a rule is what quietly goes
 * wrong: a deadline measured from the wrong charge, a deadline counted as a
 * charge, a split member shown someone else's share. None of those changes
 * the markup enough to fail a template test.
 *
 * The clock is frozen mid-January.
 */
final class CalendarMonthTest extends DatabaseTestCase
{
    private ContainerInterface $container;
    private SubscriptionRepository $subscriptions;

    private int $alice;
    private int $bob;
    private int $household;

    protected function setUp(): void
    {
        parent::setUp();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $app = $bootstrap(true, [
            SessionInterface::class => new ArraySession(),
            MailerInterface::class => new RecordingMailer(),
            Clock::class => FrozenClock::at('2026-01-15 09:00:00'),
        ]);
        $container = $app->getContainer();
        self::assertNotNull($container);
        $this->container = $container;

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setBaseCurrency('GBP');
        $settings->markRatesAttempted(new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->bob = $users->create('bob@example.test', 'Bob', 'hash');
        $this->household = (new HouseholdRepository($this->db))->create('Shared house', $this->alice);
        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bob, Role::Editor);

        $this->subscriptions = new SubscriptionRepository($this->db);
    }

    public function testADeadlineIsMeasuredFromTheNextChargeAndLandsInItsOwnMonth(): void
    {
        // Thirty days' notice on a charge due 20 February puts the deadline in
        // January — a different month from the charge, which is the entire
        // reason the deadline is worth showing separately.
        $this->createSubscription('Gym', 4000, '2026-02-20', notice: 30);

        $deadlines = $this->deadlines($this->month('2026-01'));

        self::assertCount(1, $deadlines);
        self::assertSame('2026-01-21', $deadlines[0]['date']->format('Y-m-d'));
        self::assertNotNull($deadlines[0]['charge_date']);
        self::assertSame('2026-02-20', $deadlines[0]['charge_date']->format('Y-m-d'));
        self::assertSame(4000, $deadlines[0]['amount']->amountMinor);

        // And it is not repeated against the month the charge falls in.
        self::assertSame([], $this->deadlines($this->month('2026-02')));
    }

    public function testADeadlineIsMeasuredFromATrialsConversionRatherThanAStalePaymentDate(): void
    {
        // A trial's `next_payment_date` column is either null or a leftover.
        // Measuring from it would say a deadline had passed while there was
        // still a fortnight to act.
        $this->createTrial('Trial plan', trialEnd: '2026-02-10', convertsToMinor: 1299, notice: 14);

        $deadlines = $this->deadlines($this->month('2026-01'));

        self::assertCount(1, $deadlines);
        self::assertSame('2026-01-27', $deadlines[0]['date']->format('Y-m-d'));
    }

    public function testAContractEndIsNotDrawnAsADeadline(): void
    {
        // Broadband's contract ends in January; only the gym's notice period
        // is a cancel-by deadline on the calendar.
        $broadband = $this->createSubscription('Broadband', 3500, '2026-02-10');
        $this->db->execute(
            "UPDATE subscriptions SET contract_ends_on = '2026-01-25' WHERE id = :id",
            ['id' => $broadband],
        );
        $this->createSubscription('Gym', 4000, '2026-02-20', notice: 30);

        $deadlines = $this->deadlines($this->month('2026-01'));

        self::assertCount(1, $deadlines);
        self::assertSame('Gym', $deadlines[0]['subscription']->name);
    }

    public function testADeadlineThatHasAlreadyPassedIsNotShown(): void
    {
        // Seven days' notice on a charge due on the 18th: the deadline was the
        // 11th and today is the 15th.
        $this->createSubscription('Missed it', 1000, '2026-01-18', notice: 7);

        self::assertSame([], $this->deadlines($this->month('2026-01')));
    }

    public function testASubscriptionWithNoNoticePeriodHasNoDeadline(): void
    {
        $this->createSubscription('Cancel any time', 1000, '2026-02-20');

        self::assertSame([], $this->deadlines($this->month('2026-01')));
        self::assertSame([], $this->deadlines($this->month('2026-02')));
    }

    public function testADeadlineIsDrawnButNeverCounted(): void
    {
        $this->createSubscription('Gym', 4000, '2026-02-20', notice: 30);

        $january = $this->month('2026-01');

        self::assertSame(0, $january['count']);
        self::assertSame([], $january['totals']['totals']);
        self::assertNull($january['heaviest']);
        self::assertSame([], $january['days'][0]['figures'] ?? []);
    }

    public function testATrialConversionIsItsOwnKindAndItsRenewalsAreCharges(): void
    {
        $this->createTrial('Trial plan', trialEnd: '2026-01-20', convertsToMinor: 1299);

        self::assertSame(['trial'], $this->kinds($this->month('2026-01')));
        self::assertSame(['charge'], $this->kinds($this->month('2026-02')));
        self::assertSame(1, $this->month('2026-01')['count']);
    }

    public function testADaysItemsAreDrawnDeadlineThenTrialThenCharge(): void
    {
        $this->createSubscription('Alpha', 1000, '2026-01-25');
        $this->createTrial('Beta', trialEnd: '2026-01-25', convertsToMinor: 500);
        // Thirty days before 24 February.
        $this->createSubscription('Gamma', 2000, '2026-02-24', notice: 30);

        $day = $this->day($this->month('2026-01'), '2026-01-25');

        self::assertSame(['cancel_by', 'trial', 'charge'], array_column($day['items'], 'kind'));
        // The day's total is the two that cost money.
        self::assertSame([['currency' => 'GBP', 'amount_minor' => 1500]], $day['figures']['totals'] ?? null);
    }

    public function testATieForTheHeaviestDayGoesToTheEarlierOne(): void
    {
        $this->createSubscription('First', 1000, '2026-01-20');
        $this->createSubscription('Second', 1000, '2026-01-27');

        $heaviest = $this->month('2026-01')['heaviest'];

        self::assertNotNull($heaviest);
        self::assertSame('2026-01-20', $heaviest['date']->format('Y-m-d'));
        self::assertSame(1000, $heaviest['amount']->amountMinor);
    }

    public function testADeadlineCostsThisMembersShareWhenScopedToThem(): void
    {
        // The deadline is a selection over the same charges the grid is drawn
        // from, so the "just mine" toggle reaches it without it knowing
        // anything about splits.
        $id = $this->createSubscription('Family plan', 1500, '2026-02-20', notice: 30);
        $this->container->get(SplitService::class)->update($this->scope(), $id, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->alice => 1, $this->bob => 1],
        ]);

        self::assertSame(1500, $this->deadlines($this->month('2026-01'))[0]['amount']->amountMinor);
        self::assertSame(750, $this->deadlines($this->month('2026-01', $this->alice))[0]['amount']->amountMinor);
    }

    public function testAMemberWhoBearsNoneOfASplitSeesNeitherItsDeadlineNorItsCharge(): void
    {
        $id = $this->createSubscription('Bobs plan', 1000, '2026-01-20', notice: 30);
        $this->container->get(SplitService::class)->update($this->scope(), $id, [
            'split_mode' => SplitMode::Custom->value,
            'shares' => [$this->bob => 1],
        ]);

        self::assertSame([], $this->month('2026-01', $this->alice)['days']);
        self::assertNotSame([], $this->month('2026-01', $this->bob)['days']);
    }

    /**
     * @param array<string, mixed> $month
     * @return list<array<string, mixed>>
     */
    private function deadlines(array $month): array
    {
        $deadlines = [];
        foreach ($month['days'] as $day) {
            foreach ($day['items'] as $item) {
                if ($item['kind'] === 'cancel_by') {
                    $deadlines[] = $item;
                }
            }
        }

        return $deadlines;
    }

    /**
     * @param array<string, mixed> $month
     * @return list<string>
     */
    private function kinds(array $month): array
    {
        $kinds = [];
        foreach ($month['days'] as $day) {
            foreach ($day['items'] as $item) {
                $kinds[] = $item['kind'];
            }
        }

        return $kinds;
    }

    /**
     * @param array<string, mixed> $month
     * @return array<string, mixed>
     */
    private function day(array $month, string $date): array
    {
        foreach ($month['days'] as $day) {
            if ($day['key'] === $date) {
                return $day;
            }
        }

        self::fail('Nothing on ' . $date);
    }

    /**
     * @return array<string, mixed>
     */
    private function month(string $month, ?int $forUserId = null): array
    {
        return $this->container->get(CalendarService::class)->month(
            $this->scope(),
            new DateTimeImmutable($month . '-01 00:00:00'),
            WeekStart::Monday,
            $forUserId,
        );
    }

    private function createSubscription(string $name, int $priceMinor, string $nextPayment, ?int $notice = null): int
    {
        return $this->subscriptions->create($this->scope(), array_filter([
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $nextPayment,
            'anchor_day' => (int) (new DateTimeImmutable($nextPayment))->format('j'),
            'notice_period_amount' => $notice,
            'notice_period_unit' => $notice === null ? null : NoticePeriod::UNIT_DAYS,
            'is_active' => true,
        ], static fn (mixed $value): bool => $value !== null), []);
    }

    private function createTrial(string $name, string $trialEnd, int $convertsToMinor, ?int $notice = null): int
    {
        return $this->subscriptions->create($this->scope(), array_filter([
            'name' => $name,
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => $trialEnd,
            'converts_to_price_minor' => $convertsToMinor,
            'notice_period_amount' => $notice,
            'notice_period_unit' => $notice === null ? null : NoticePeriod::UNIT_DAYS,
            'is_active' => true,
        ], static fn (mixed $value): bool => $value !== null), []);
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Shared);
    }
}
