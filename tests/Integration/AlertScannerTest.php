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
}
