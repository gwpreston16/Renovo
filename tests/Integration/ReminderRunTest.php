<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AlertType;
use App\Domain\BudgetPeriod;
use App\Domain\DigestMode;
use App\Domain\Role;
use DateTimeImmutable;

/**
 * The scheduler's run, end to end: what fires, when, for whom, and how often.
 */
final class ReminderRunTest extends NotificationTestCase
{
    // ------------------------------------------------------------------
    // Lead times
    // ------------------------------------------------------------------

    public function testARenewalFiresAtEachConfiguredLeadTime(): void
    {
        $this->addChannel($this->alice);
        // 30, 7 and 1 days before 15 October.
        $this->createSubscription('Netflix', 1099, '2026-10-15', $this->alice);

        $fired = [];
        foreach (['2026-09-15', '2026-10-08', '2026-10-14'] as $day) {
            $this->clock->advanceTo(new DateTimeImmutable($day . ' 08:00:00'));
            $this->runner->run();
            $fired[$day] = count($this->notifier->sent);
        }

        self::assertSame(['2026-09-15' => 1, '2026-10-08' => 2, '2026-10-14' => 3], $fired);
    }

    public function testNothingIsSentOnTheDaysInBetween(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Netflix', 1099, '2026-10-15', $this->alice);

        $this->runner->run();
        self::assertCount(1, $this->notifier->sent);

        foreach (['2026-09-16', '2026-09-20', '2026-10-01'] as $day) {
            $this->clock->advanceTo(new DateTimeImmutable($day . ' 08:00:00'));
            $this->runner->run();
        }

        self::assertCount(1, $this->notifier->sent, 'a daily scheduler must not mean a daily reminder');
    }

    public function testAMissedDayDoesNotLoseTheReminder(): void
    {
        // The scheduler is down on the seventh day before — a reboot, a full
        // disk. When it next runs, five days out, the seven-day reminder is
        // still owed and is still sent.
        $this->addChannel($this->alice);
        $this->createSubscription('Netflix', 1099, '2026-10-15', $this->alice);

        $this->runner->run();                                              // 30 days out
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-10 08:00:00')); // 5 days out
        $this->runner->run();

        self::assertCount(2, $this->notifier->sent);
        self::assertStringContainsString('renews in 5 days', $this->notifier->titles()[1]);
    }

    public function testASubscriptionCanSilenceItself(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Cheap app', 99, '2026-10-15', $this->alice, ['reminder_days' => '']);

        $this->runner->run();

        self::assertSame([], $this->notifier->sent);
    }

    public function testASubscriptionCanOverrideTheScheduleWithItsOwn(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Insurance', 25000, '2026-11-14', $this->alice, ['reminder_days' => '60']);

        // Sixty days before 14 November is 15 September — today.
        $this->runner->run();

        self::assertCount(1, $this->notifier->sent);
        self::assertStringContainsString('Insurance', $this->notifier->titles()[0]);
    }

    // ------------------------------------------------------------------
    // The alert types
    // ------------------------------------------------------------------

    public function testATrialAboutToConvertIsAnnouncedAsAConversionNotARenewal(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Streaming trial', 0, null, $this->alice, [
            'is_trial' => true,
            'trial_end_date' => '2026-09-16',
            'converts_to_price_minor' => 1299,
        ]);

        $this->runner->run();

        self::assertSame([AlertType::TrialConversion->value], $this->notifier->alertTypes());
        self::assertStringContainsString('trial ends tomorrow', $this->notifier->titles()[0]);
        self::assertStringContainsString('£12.99', $this->notifier->sent[0]['alert']->body());
    }

    public function testACancellationDeadlineIsItsOwnAlert(): void
    {
        $this->addChannel($this->alice);
        // Renews 15 October with 30 days' notice, so the deadline is today.
        $this->createSubscription('Gym', 4000, '2026-10-15', $this->alice, [
            'notice_period_amount' => 30,
            'notice_period_unit' => 'days',
        ]);

        $this->runner->run();

        $types = $this->notifier->alertTypes();
        self::assertContains(AlertType::CancelBy->value, $types);
        self::assertContains(AlertType::Renewal->value, $types);
    }

    public function testNoCancelByAlertWithoutANoticePeriod(): void
    {
        // Without notice the deadline is the renewal date, which the renewal
        // alert already covers. Two messages about one event is noise.
        $this->addChannel($this->alice);
        $this->createSubscription('Netflix', 1099, '2026-10-15', $this->alice);

        $this->runner->run();

        self::assertSame([AlertType::Renewal->value], $this->notifier->alertTypes());
    }

    public function testAnOverduePaymentIsCaughtUpBeforeAnythingIsAnnounced(): void
    {
        // The reminder must describe the state the application would show if
        // the user opened it now. A payment date that has slipped into the past
        // is rolled forward first, so what goes out is the next real charge
        // rather than one that has already happened.
        $this->addChannel($this->alice);
        $this->createSubscription('Netflix', 1099, '2026-09-01', $this->alice);

        $this->runner->run();

        self::assertCount(1, $this->notifier->sent);
        self::assertSame('2026-10-01', $this->notifier->sent[0]['alert']->dueDate?->format('Y-m-d'));
    }

    // ------------------------------------------------------------------
    // Who hears about what
    // ------------------------------------------------------------------

    public function testAMemberIsNotToldAboutSomebodyElsesSubscription(): void
    {
        // Alice can see Bob's subscription in a SHARED household. Being told
        // about it is a different question, and the answer is no: a household
        // of four would otherwise quadruple everybody's notifications.
        $this->addChannel($this->alice);
        $this->createSubscription('Bobs gym', 4000, '2026-10-15', $this->bob);

        $this->runner->run();

        self::assertSame([], $this->notifier->sent);
    }

    public function testThePayerIsToldEvenWhenSomebodyElseOwnsIt(): void
    {
        $this->addChannel($this->bob);
        $this->createSubscription('Family plan', 1500, '2026-10-15', $this->alice, [
            'payer_user_id' => $this->bob,
        ]);

        $this->runner->run();

        self::assertCount(1, $this->notifier->sent);
        self::assertSame($this->bob, $this->notifier->sent[0]['user']->id);
    }

    public function testEveryHouseholdAMemberBelongsToIsChecked(): void
    {
        // ScopeFactory defaults to a user's first membership. A runner that
        // asked it once would silently cover one household and skip the other,
        // with nothing in any log to say so.
        $second = $this->households->create('Flat share', $this->alice);
        $this->memberships->create($second, $this->alice, Role::OwnerAdmin);

        $this->addChannel($this->alice);
        $this->createSubscription('Netflix', 1099, '2026-10-15', $this->alice);
        $this->subscriptions->create($this->scope($this->alice, household: $second), [
            'name' => 'Broadband',
            'price_minor' => 3500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-10-15',
            'anchor_day' => 15,
            'is_active' => true,
            'owner_user_id' => $this->alice,
        ], []);

        $this->runner->run();

        $titles = implode(' | ', $this->notifier->titles());
        self::assertStringContainsString('Netflix', $titles);
        self::assertStringContainsString('Broadband', $titles);
    }

    // ------------------------------------------------------------------
    // Budgets
    // ------------------------------------------------------------------

    public function testABudgetBreachIsAnnouncedOnceAndRearmsOnlyAfterDroppingUnder(): void
    {
        $this->addChannel($this->alice);
        $id = $this->createSubscription('Streaming', 3000, '2026-09-20', $this->alice);
        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '20.00',
            'currency' => 'GBP',
        ]);

        $this->runner->run();
        self::assertSame(1, $this->budgetAlertCount());

        // Still over on the following days, and still over is not news.
        foreach (['2026-09-16', '2026-09-17'] as $day) {
            $this->clock->advanceTo(new DateTimeImmutable($day . ' 08:00:00'));
            $this->runner->run();
        }
        self::assertSame(1, $this->budgetAlertCount());

        // Back under, silently.
        $this->subscriptionService->setActive($this->scope($this->alice), $id, false);
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-18 08:00:00'));
        $this->runner->run();
        self::assertSame(1, $this->budgetAlertCount());

        // Over again: a new crossing, and it is announced.
        $this->subscriptionService->setActive($this->scope($this->alice), $id, true);
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-19 08:00:00'));
        $this->runner->run();
        self::assertSame(2, $this->budgetAlertCount());
    }

    /**
     * The Notifications page's budget switch (Phase 28). Off, and the breach
     * is not sent — but it is still recorded, so turning the switch back on
     * does not announce a crossing that happened while it was off as though
     * it were new.
     */
    public function testBudgetAlertsTurnedOffAreNotSentButTheBreachIsStillRecorded(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Streaming', 3000, '2026-09-20', $this->alice);
        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '20.00',
            'currency' => 'GBP',
        ]);

        $this->notificationSettings->savePreferences($this->alice, [
            'digest_mode' => DigestMode::Immediate->value,
            'budget_alerts' => '0',
        ]);
        $this->runner->run();
        self::assertSame(0, $this->budgetAlertCount());

        $this->notificationSettings->savePreferences($this->alice, [
            'digest_mode' => DigestMode::Immediate->value,
            'budget_alerts' => '1',
        ]);
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-16 08:00:00'));
        $this->runner->run();
        self::assertSame(0, $this->budgetAlertCount(), 'the breach was already recorded while alerts were off');
    }

    /**
     * Several lead times chosen as the page's chips — posted as a list behind
     * the hidden empty entry — persist, and each fires for every dated alert
     * alike: a renewal, a trial conversion and a cancel-by deadline, all on 15
     * October, are each announced fourteen days and three days before.
     */
    public function testLeadTimesChosenAsChipsEachFireForEveryDatedAlert(): void
    {
        $this->addChannel($this->alice);
        $this->notificationSettings->savePreferences($this->alice, [
            'lead_days' => ['', '14', '3'],
            'digest_mode' => DigestMode::Immediate->value,
        ]);
        self::assertSame([14, 3], $this->notificationSettings->preferences($this->alice)->leadDays);

        $this->createSubscription('Netflix', 1099, '2026-10-15', $this->alice);
        $this->createSubscription('Streaming trial', 0, null, $this->alice, [
            'is_trial' => true,
            'trial_end_date' => '2026-10-15',
            'converts_to_price_minor' => 1299,
        ]);
        // Renews 14 November with 30 days' notice: the deadline is 15 October.
        $this->createSubscription('Gym', 4000, '2026-11-14', $this->alice, [
            'notice_period_amount' => 30,
            'notice_period_unit' => 'days',
        ]);

        $expected = [AlertType::CancelBy->value, AlertType::Renewal->value, AlertType::TrialConversion->value];

        foreach (['2026-10-01', '2026-10-12'] as $day) {
            $before = count($this->notifier->sent);
            $this->clock->advanceTo(new DateTimeImmutable($day . ' 08:00:00'));
            $this->runner->run();

            $types = array_slice($this->notifier->alertTypes(), $before);
            sort($types);
            self::assertSame($expected, $types, 'on ' . $day);
        }

        // And nothing on a day that is neither.
        $before = count($this->notifier->sent);
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-08 08:00:00'));
        $this->runner->run();
        self::assertCount($before, $this->notifier->sent);
    }

    /**
     * @phpstan-impure
     */
    private function budgetAlertCount(): int
    {
        return count(array_filter(
            $this->notifier->alertTypes(),
            static fn (string $type): bool => $type === AlertType::BudgetExceeded->value,
        ));
    }

    public function testABudgetUnderItsLimitSaysNothing(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Streaming', 500, '2026-09-20', $this->alice);
        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '20.00',
            'currency' => 'GBP',
        ]);

        $this->runner->run();

        self::assertNotContains(AlertType::BudgetExceeded->value, $this->notifier->alertTypes());
    }

    public function testAWarningThresholdDoesNotFireAnAlert(): void
    {
        // The phase asks for "projected to exceed". A budget at 95% of its
        // limit is shown as a warning on the page and is not worth waking
        // somebody up for.
        $this->addChannel($this->alice);
        $this->createSubscription('Streaming', 1900, '2026-09-20', $this->alice);
        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '20.00',
            'currency' => 'GBP',
            'warn_threshold_percent' => '90',
        ]);

        $this->runner->run();

        self::assertNotContains(AlertType::BudgetExceeded->value, $this->notifier->alertTypes());
    }

    public function testOnlyTheBudgetsOwnerIsTold(): void
    {
        $this->addChannel($this->bob);
        $this->createSubscription('Streaming', 3000, '2026-09-20', $this->alice);
        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Alice budget',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '20.00',
            'currency' => 'GBP',
        ]);

        $this->runner->run();

        self::assertSame([], $this->notifier->sent);
    }

    // ------------------------------------------------------------------
    // Digests
    // ------------------------------------------------------------------

    public function testADigestUserHearsNothingUntilTheirDigestDay(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Netflix', 1099, '2026-09-25', $this->alice);
        $this->notificationSettings->savePreferences($this->alice, [
            'lead_days' => '30,7,1',
            'digest_mode' => DigestMode::Weekly->value,
            'digest_day' => '1',
        ]);

        // 15 September 2026 is a Tuesday.
        $this->runner->run();
        self::assertSame([], $this->notifier->sent);

        // The following Monday.
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-21 08:00:00'));
        $this->runner->run();
        self::assertCount(1, $this->notifier->sent);
    }

    public function testADigestCollectsEverythingIntoOneMessage(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Netflix', 1099, '2026-09-23', $this->alice);
        $this->createSubscription('Spotify', 1199, '2026-09-24', $this->alice);
        $this->createSubscription('Gym', 4000, '2026-09-25', $this->alice, [
            'notice_period_amount' => 2,
            'notice_period_unit' => 'days',
        ]);

        $this->notificationSettings->savePreferences($this->alice, [
            'lead_days' => '30,7,1',
            'digest_mode' => DigestMode::Weekly->value,
            'digest_day' => '1',
        ]);

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-21 08:00:00'));
        $this->runner->run();

        self::assertCount(1, $this->notifier->sent);

        $body = $this->notifier->sent[0]['alert']->body();
        self::assertStringContainsString('Netflix', $body);
        self::assertStringContainsString('Spotify', $body);
        self::assertStringContainsString('Gym', $body);
        // Grouped by kind rather than listed flat.
        self::assertStringContainsString('Upcoming renewal:', $body);
        self::assertStringContainsString('Cancellation deadline:', $body);
    }

    public function testADigestIsNotSentTwiceInTheSamePeriod(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Netflix', 1099, '2026-09-23', $this->alice);
        $this->notificationSettings->savePreferences($this->alice, [
            'lead_days' => '30,7,1',
            'digest_mode' => DigestMode::Weekly->value,
            'digest_day' => '1',
        ]);

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-21 08:00:00'));
        $this->runner->run();
        $this->runner->run();

        self::assertCount(1, $this->notifier->sent);
    }

    public function testAMonthlyDigestGoesOutOnItsDayOfTheMonth(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Netflix', 1099, '2026-10-10', $this->alice);
        $this->notificationSettings->savePreferences($this->alice, [
            'lead_days' => '30,7,1',
            'digest_mode' => DigestMode::Monthly->value,
            'digest_day' => '1',
        ]);

        $this->clock->advanceTo(new DateTimeImmutable('2026-10-01 08:00:00'));
        $this->runner->run();

        self::assertCount(1, $this->notifier->sent);
        self::assertStringContainsString('Monthly subscription summary', $this->notifier->titles()[0]);
    }

    public function testADigestWindowEndsBeforeTheNextIssue(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Netflix', 1099, '2026-11-02', $this->alice);
        $this->notificationSettings->savePreferences($this->alice, [
            'lead_days' => '30,7,1',
            'digest_mode' => DigestMode::Weekly->value,
            'digest_day' => '1',
        ]);

        // Monday 26 October: 2 November is the next issue's today, not this one's.
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-26 08:00:00'));
        $this->runner->run();
        self::assertSame([], $this->notifier->alertTypes());

        $this->clock->advanceTo(new DateTimeImmutable('2026-11-02 08:00:00'));
        $this->runner->run();
        self::assertCount(1, $this->notifier->sent);
        self::assertStringContainsString('Netflix', $this->notifier->sent[0]['alert']->body());
    }

    public function testAMonthlyDigestWindowEndsBeforeTheNextMonthsIssue(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Netflix', 1099, '2026-03-28', $this->alice);
        $this->createSubscription('Spotify', 1199, '2026-03-27', $this->alice);
        $this->notificationSettings->savePreferences($this->alice, [
            'lead_days' => '30,7,1',
            'digest_mode' => DigestMode::Monthly->value,
            'digest_day' => '28',
        ]);

        $this->clock->advanceTo(new DateTimeImmutable('2026-02-28 08:00:00'));
        $this->runner->run();

        self::assertCount(1, $this->notifier->sent);
        $body = $this->notifier->sent[0]['alert']->body();
        self::assertStringContainsString('Spotify', $body);
        self::assertStringNotContainsString('Netflix', $body);
    }
}
