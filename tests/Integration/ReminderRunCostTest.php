<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AlertType;
use App\Notification\Alert;

/**
 * What a run costs when most of what it finds was told on an earlier day
 * (K221), and that dropping those alerts early changes nothing about what is
 * sent: only an alert the ledger has finished with is left out.
 */
final class ReminderRunCostTest extends NotificationTestCase
{
    private const MAX_ATTEMPTS = 3;

    public function testSettledAlertsAreNotSentAndTheirNumberAddsNoStatements(): void
    {
        $channel = $this->addChannel($this->alice);
        $few = $this->settledAlerts($this->alice, $channel, 3);
        $many = $this->settledAlerts($this->alice, $channel, 30, 100);

        $beforeFew = $this->db->statementCount();
        $sentFew = $this->dispatcher->dispatch($this->user($this->alice), $few);
        $costFew = $this->db->statementCount() - $beforeFew;

        $beforeMany = $this->db->statementCount();
        $sentMany = $this->dispatcher->dispatch($this->user($this->alice), array_merge($few, $many));
        $costMany = $this->db->statementCount() - $beforeMany;

        self::assertSame(0, $sentFew);
        self::assertSame(0, $sentMany);
        self::assertSame([], $this->notifier->sent);
        self::assertSame($costFew, $costMany, 'thirty-three settled alerts cost what three do');
        self::assertLessThanOrEqual(6, $costMany, 'a bounded number of reads for the whole batch');
    }

    public function testAUsersChannelsAreReadOnceForManyAlertsOfOneTypeAndOncePerType(): void
    {
        $channel = $this->addChannel($this->alice);
        $renewals = $this->settledAlerts($this->alice, $channel, 20);
        $contracts = $this->settledAlerts($this->alice, $channel, 20, 100, AlertType::ContractEnding);

        $before = $this->db->statementCount();
        $this->dispatcher->dispatch($this->user($this->alice), [$renewals[0], $contracts[0]]);
        $two = $this->db->statementCount() - $before;

        $before = $this->db->statementCount();
        $this->dispatcher->dispatch($this->user($this->alice), array_merge($renewals, $contracts));
        $forty = $this->db->statementCount() - $before;

        self::assertSame($two, $forty, 'forty alerts of two types read the channels as two alerts of two types do');
    }

    public function testAFailedAlertWithAnAttemptLeftIsStillRetried(): void
    {
        $channel = $this->addChannel($this->alice);
        $alert = $this->alert(1);
        $this->recordFailures($this->alice, $channel, $alert, 1);

        $sent = $this->dispatcher->dispatch($this->user($this->alice), [$alert]);

        self::assertSame(1, $sent);
    }

    public function testAnAlertWhoseAttemptsAreUsedUpIsNotRetried(): void
    {
        $channel = $this->addChannel($this->alice);
        $alert = $this->alert(1);
        $this->recordFailures($this->alice, $channel, $alert, self::MAX_ATTEMPTS);

        $sent = $this->dispatcher->dispatch($this->user($this->alice), [$alert]);

        self::assertSame(0, $sent);
        self::assertSame([], $this->notifier->sent);
    }

    public function testAnAlertWithNoLedgerRowIsSent(): void
    {
        $this->addChannel($this->alice);

        self::assertSame(1, $this->dispatcher->dispatch($this->user($this->alice), [$this->alert(1)]));
    }

    public function testAnAlertSentOnOneOfTwoChannelsStillGoesToTheOther(): void
    {
        $first = $this->addChannel($this->alice, 'Phone');
        $second = $this->addChannel($this->alice, 'Tablet');
        $alert = $this->alert(1);
        $this->recordSent($this->alice, $first, $alert);

        $sent = $this->dispatcher->dispatch($this->user($this->alice), [$alert]);

        self::assertSame(1, $sent);
        self::assertSame($second, $this->notifier->sent[0]['channel']->id);
    }

    public function testAnAlertSettledOnBothChannelsIsLeftOut(): void
    {
        $first = $this->addChannel($this->alice, 'Phone');
        $second = $this->addChannel($this->alice, 'Tablet');
        $alert = $this->alert(1);
        $this->recordSent($this->alice, $first, $alert);
        $this->recordSent($this->alice, $second, $alert);

        self::assertSame(0, $this->dispatcher->dispatch($this->user($this->alice), [$alert]));
    }

    public function testOneUsersSettledRowDoesNotSettleAnotherUsersAlert(): void
    {
        $aliceChannel = $this->addChannel($this->alice);
        $this->addChannel($this->bob);
        $alert = $this->alert(1);
        $this->recordSent($this->alice, $aliceChannel, $alert);

        self::assertSame(1, $this->dispatcher->dispatch($this->user($this->bob), [$alert]));
    }

    public function testAnOccurrenceLongerThanTheLedgerKeepsStillMatchesItsRow(): void
    {
        // The ledger stores the first 64 characters of the key, so the early
        // check has to compare the same cut.
        $channel = $this->addChannel($this->alice);
        $alert = $this->alert(1, str_repeat('k', 80));
        $this->recordSent($this->alice, $channel, $alert);

        self::assertSame(0, $this->dispatcher->dispatch($this->user($this->alice), [$alert]));
    }

    /**
     * @return list<Alert>
     */
    private function settledAlerts(
        int $userId,
        int $channelId,
        int $count,
        int $firstSubject = 1,
        AlertType $type = AlertType::Renewal,
    ): array {
        $alerts = [];
        for ($index = 0; $index < $count; $index++) {
            $alert = $this->alert($firstSubject + $index, '2026-10-01:7', $type);
            $this->recordSent($userId, $channelId, $alert);
            $alerts[] = $alert;
        }

        return $alerts;
    }

    private function alert(
        int $subjectId,
        string $occurrence = '2026-10-01:7',
        AlertType $type = AlertType::Renewal,
    ): Alert {
        return new Alert(
            $type,
            Alert::SUBJECT_SUBSCRIPTION,
            $subjectId,
            $occurrence,
            'Sub ' . $subjectId,
            ['Due soon.'],
        );
    }

    private function recordSent(int $userId, int $channelId, Alert $alert): void
    {
        $id = $this->log->claim(
            $userId,
            $channelId,
            $alert->type,
            $alert->subjectType,
            $alert->subjectId,
            $alert->occurrenceKey,
            self::MAX_ATTEMPTS,
        );
        self::assertNotNull($id);
        $this->log->markSent($id);
    }

    /**
     * Leave the ledger with this many failed attempts on the alert.
     */
    private function recordFailures(int $userId, int $channelId, Alert $alert, int $attempts): void
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $id = $this->log->claim(
                $userId,
                $channelId,
                $alert->type,
                $alert->subjectType,
                $alert->subjectId,
                $alert->occurrenceKey,
                self::MAX_ATTEMPTS + 5,
            );
            self::assertNotNull($id);
            $this->log->markFailed($id, 'down');
        }
    }
}
