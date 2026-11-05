<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AlertType;
use App\Domain\BudgetPeriod;
use App\Service\Notification\AlertScanner;
use DateTimeImmutable;

/**
 * A budget breach is announced once per crossing, retried if it failed, and
 * not announced again once the ledger may have forgotten it (K15).
 */
final class AlertScannerTest extends NotificationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSubscription('Alice music', 1000, '2026-09-20', $this->alice);
        $this->addChannel($this->alice);
        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '5.00',
            'currency' => 'GBP',
        ]);
    }

    private function breachAlerts(): int
    {
        $count = 0;
        foreach ($this->notifier->sent as $sent) {
            if ($sent['alert']->type === AlertType::BudgetExceeded) {
                $count++;
            }
        }

        return $count;
    }

    private function runOn(string $date): void
    {
        $this->clock->advanceTo(new DateTimeImmutable($date . ' 08:00:00'));
        $this->runner->run();
    }

    public function testAFailedBreachAlertIsRetriedOnTheNextRun(): void
    {
        $this->notifier->failNext();
        $this->runner->run();
        self::assertSame(0, $this->breachAlerts());

        $this->notifier->failNext(false);
        $this->runOn('2026-09-16');

        self::assertSame(1, $this->breachAlerts());
    }

    public function testASentBreachAlertIsNotRepeated(): void
    {
        $this->runner->run();
        $this->runOn('2026-09-16');
        $this->runOn('2026-09-17');

        self::assertSame(1, $this->breachAlerts());
    }

    public function testALongBreachIsNotAnnouncedAgainAfterThePrune(): void
    {
        $this->runner->run();
        self::assertSame(1, $this->breachAlerts());

        // Past the retry window, with the ledger emptied as the prune would.
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-15 08:00:00'));
        $later = '2026-09-' . (15 + AlertScanner::BREACH_RETRY_WINDOW_DAYS + 1);
        $this->db->execute('DELETE FROM notification_log');
        $this->runOn($later);

        self::assertSame(1, $this->breachAlerts(), 'a breach that has lasted months is announced once');
    }

    public function testAFailedAlertIsRetriedUntilTheWindowClosesWithTheLedgerIntact(): void
    {
        $this->notifier->failNext();
        $this->runner->run();
        $this->notifier->failNext(false);

        // Just inside the window: still owed, and sent.
        $this->runOn('2026-09-' . (15 + AlertScanner::BREACH_RETRY_WINDOW_DAYS - 1));
        self::assertSame(1, $this->breachAlerts());
    }

    public function testAFailedAlertIsNotRetriedOnceTheCrossingIsPastTheWindow(): void
    {
        $this->notifier->failNext();
        $this->runner->run();
        $this->notifier->failNext(false);

        // The failed row is still in the ledger; only the window stops it.
        $this->runOn('2026-09-' . (15 + AlertScanner::BREACH_RETRY_WINDOW_DAYS + 1));
        self::assertSame(0, $this->breachAlerts());
    }

    // ------------------------------------------------------------------
    // A date that has passed is not warned about (K200)
    // ------------------------------------------------------------------

    /**
     * @return list<AlertType>
     */
    private function dueTypes(string $name): array
    {
        $types = [];
        foreach (
            $this->scanner->dueAlerts(
                $this->scope($this->alice),
                $this->notificationSettings->preferences($this->alice),
            ) as $alert
        ) {
            if (str_contains($alert->title, $name)) {
                $types[] = $alert->type;
            }
        }

        return $types;
    }

    public function testAContractThatEndedTwoDaysAgoSendsNothing(): void
    {
        // 15 Sep is "today"; 13 Sep is -2 days, inside every lead window.
        $this->createSubscription('Sky Broadband', 3500, '2027-01-20', $this->alice, [
            'contract_ends_on' => '2026-09-13',
        ]);

        self::assertSame([], $this->dueTypes('Sky Broadband'));
    }

    public function testAContractEndingOnALeadDayStillSends(): void
    {
        // The control: the same subscription a week before the end.
        $this->createSubscription('Sky Broadband', 3500, '2027-01-20', $this->alice, [
            'contract_ends_on' => '2026-09-22',
        ]);

        self::assertSame([AlertType::ContractEnding], $this->dueTypes('Sky Broadband'));
    }

    public function testACancelByDatePastSendsNoCancelByAlert(): void
    {
        // Renews 20 Sep with 30 days' notice: the deadline was 21 Aug.
        $this->createSubscription('Gym', 4000, '2026-09-20', $this->alice, [
            'notice_period_amount' => 30,
            'notice_period_unit' => 'days',
        ]);

        // The renewal, five days out, is still announced; the deadline is not.
        self::assertSame([AlertType::Renewal], $this->dueTypes('Gym'));
    }

    public function testACancelByDateOnALeadDayStillSends(): void
    {
        // The control: the deadline is 22 Sep, a week away.
        $this->createSubscription('Gym', 4000, '2026-10-22', $this->alice, [
            'notice_period_amount' => 30,
            'notice_period_unit' => 'days',
        ]);

        self::assertSame([AlertType::CancelBy], $this->dueTypes('Gym'));
    }

    public function testATrialThatEndedTwoDaysAgoSendsNothing(): void
    {
        $this->createSubscription('Streaming trial', 0, null, $this->alice, [
            'is_trial' => true,
            'trial_end_date' => '2026-09-13',
            'converts_to_price_minor' => 1299,
        ]);

        self::assertSame([], $this->dueTypes('Streaming trial'));
    }

    public function testATrialEndingOnALeadDayStillSends(): void
    {
        $this->createSubscription('Streaming trial', 0, null, $this->alice, [
            'is_trial' => true,
            'trial_end_date' => '2026-09-22',
            'converts_to_price_minor' => 1299,
        ]);

        self::assertSame([AlertType::TrialConversion], $this->dueTypes('Streaming trial'));
    }

    public function testAPassedContractEndWritesNoNotificationLogRow(): void
    {
        $this->createSubscription('Sky Broadband', 3500, '2027-01-20', $this->alice, [
            'contract_ends_on' => '2026-09-13',
        ]);

        $this->runner->run();

        self::assertSame(
            0,
            (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM notification_log WHERE alert_type = :type',
                ['type' => AlertType::ContractEnding->value],
            ),
        );
    }
}
