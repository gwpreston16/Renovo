<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AlertType;
use App\Repository\NotificationRouteRepository;
use DateTimeImmutable;
use Phinx\Db\Adapter\AdapterInterface;

/**
 * The Contract ending alert, and the alerts a fixed term and a varying bill
 * change: when each fires, how often, and on which channels.
 */
final class ContractEndingAlertTest extends NotificationTestCase
{
    public function testAContractEndingFiresAtEachLeadTimeOnce(): void
    {
        $this->addChannel($this->alice);
        // 30, 7 and 1 days before 15 October; the next payment is far off so
        // only the contract speaks.
        $this->createSubscription('Sky Broadband', 3500, '2027-01-20', $this->alice, [
            'contract_ends_on' => '2026-10-15',
        ]);

        $fired = [];
        foreach (['2026-09-15', '2026-09-15', '2026-10-08', '2026-10-14', '2026-10-14'] as $i => $day) {
            $this->clock->advanceTo(new DateTimeImmutable($day . ' 08:00:00'));
            $this->runner->run();
            $fired[$i] = count($this->contractAlerts());
        }

        self::assertSame([1, 1, 2, 3, 3], $fired);

        $alert = $this->contractAlerts()[0];
        self::assertStringContainsString('Sky Broadband: contract ends in 30 days', $alert->title);
        self::assertStringContainsString(
            'Your Sky Broadband contract ends on 15 Oct 2026 — a good time to compare prices or renegotiate.',
            $alert->body(),
        );
        self::assertStringNotContainsString('overpay', $alert->body());
    }

    public function testAMovedEndDateIsANewAlert(): void
    {
        $this->addChannel($this->alice);
        $id = $this->createSubscription('Sky Broadband', 3500, '2027-01-20', $this->alice, [
            'contract_ends_on' => '2026-10-15',
        ]);

        $this->runner->run();
        self::assertCount(1, $this->contractAlerts());

        // Renegotiated a week later: the new end is thirty days out again.
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-22 08:00:00'));
        $this->db->execute(
            "UPDATE subscriptions SET contract_ends_on = '2026-10-22' WHERE id = :id",
            ['id' => $id],
        );
        $this->runner->run();

        self::assertCount(2, $this->contractAlerts());
        self::assertStringContainsString('22 Oct 2026', $this->contractAlerts()[1]->body());
    }

    public function testTurnedOffTheContractAlertIsNotSentButTheRenewalIs(): void
    {
        $this->addChannel($this->alice);
        $this->notificationSettings->savePreferences($this->alice, [
            'lead_days' => ['30', '7', '1'],
            'digest_mode' => 'immediate',
            'digest_day' => '1',
            'contract_alerts' => '0',
        ]);
        $this->createSubscription('Sky Broadband', 3500, '2026-10-15', $this->alice, [
            'contract_ends_on' => '2026-10-15',
        ]);

        $this->runner->run();

        self::assertSame([AlertType::Renewal->value], $this->notifier->alertTypes());
        self::assertFalse($this->notificationSettings->preferences($this->alice)->contractAlerts);
    }

    public function testAPausedRowIsNotWarnedAboutItsContract(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Sky Broadband', 3500, '2027-01-20', $this->alice, [
            'contract_ends_on' => '2026-10-15',
            'is_active' => false,
        ]);

        $this->runner->run();

        self::assertSame([], $this->notifier->sent);
    }

    public function testADigestListsTheContractUnderItsOwnHeading(): void
    {
        $this->addChannel($this->alice);
        $this->notificationSettings->savePreferences($this->alice, [
            'lead_days' => ['30', '7', '1'],
            'digest_mode' => 'weekly',
            'digest_day' => '2',
        ]);
        $this->createSubscription('Sky Broadband', 3500, '2027-01-20', $this->alice, [
            'contract_ends_on' => '2026-09-18',
        ]);

        // Tuesday 15 September is the digest day.
        $this->runner->run();

        self::assertCount(1, $this->notifier->sent);
        self::assertStringContainsString('Contract ending', $this->notifier->sent[0]['alert']->body());
    }

    public function testExistingRenewalRoutesGainTheContractAlert(): void
    {
        $phone = $this->addChannel($this->alice, 'Phone');
        $email = $this->addChannel($this->alice, 'Email');
        $routes = new NotificationRouteRepository($this->db, $this->clock);
        // Renewals go to the phone; the email hears about budgets only.
        $routes->replaceForUser($this->alice, [
            $phone => [AlertType::Renewal],
            $email => [AlertType::BudgetExceeded],
        ]);

        $this->copyRoutes();
        $this->copyRoutes();

        $saved = $routes->findForUser($this->alice);
        self::assertSame(['contract_ending', 'renewal'], $this->sorted($saved[$phone]));
        self::assertSame(['budget_exceeded'], $saved[$email]);

        $this->createSubscription('Sky Broadband', 3500, '2027-01-20', $this->alice, [
            'contract_ends_on' => '2026-10-15',
        ]);
        $this->runner->run();

        self::assertCount(1, $this->notifier->sent);
        self::assertSame('Phone', $this->notifier->sent[0]['channel']->label);
    }

    public function testTheRouteCopyIsUndoneByItsDown(): void
    {
        $phone = $this->addChannel($this->alice);
        $routes = new NotificationRouteRepository($this->db, $this->clock);
        $routes->replaceForUser($this->alice, [$phone => [AlertType::Renewal]]);

        $this->copyRoutes();
        $this->copyRoutes('down');

        self::assertSame([$phone => ['renewal']], $routes->findForUser($this->alice));
    }

    public function testNoRenewalIsAnnouncedAfterAFixedTermsLastPayment(): void
    {
        $this->addChannel($this->alice);
        // The last payment is 15 September; nobody has caught it up, so the
        // stored date still reads 15 October, which is not a charge.
        $this->createSubscription('Phone finance', 3000, '2026-10-15', $this->alice, [
            'payments_end_on' => '2026-09-30',
        ]);

        self::assertSame([], $this->scanner->dueAlerts(
            $this->scope($this->alice),
            $this->notificationSettings->preferences($this->alice),
        ));
    }

    public function testAVaryingBillsRenewalSaysAbout(): void
    {
        $this->addChannel($this->alice);
        $this->createSubscription('Octopus Energy', 5800, '2026-10-15', $this->alice, [
            'amount_varies' => true,
        ]);

        $this->runner->run();

        self::assertStringContainsString(
            'About £58.00 is due on 15 Oct 2026.',
            $this->notifier->sent[0]['alert']->body(),
        );
    }

    /**
     * @return list<\App\Notification\Alert>
     */
    private function contractAlerts(): array
    {
        return array_values(array_map(
            static fn (array $sent): \App\Notification\Alert => $sent['alert'],
            array_filter(
                $this->notifier->sent,
                static fn (array $sent): bool => $sent['alert']->type === AlertType::ContractEnding,
            ),
        ));
    }

    /**
     * Run the route-copy migration's SQL against the test database, through
     * an adapter that hands each statement to it.
     */
    private function copyRoutes(string $direction = 'up'): void
    {
        require_once __DIR__ . '/../../migrations/20270101000005_copy_renewal_routes_to_contract_ending.php';

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('execute')->willReturnCallback(
            fn (string $sql): int => $this->db->execute($sql),
        );

        $migration = new \CopyRenewalRoutesToContractEnding('testing', 20270101000005);
        $migration->setAdapter($adapter);
        $migration->{$direction}();
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
