<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestKeys;
use App\Service\SecretsRotationService;
use App\Service\AuditLogService;
use App\Security\RequestContextHolder;
use App\Security\RequestContext;
use App\Security\KeyRing;
use App\Repository\StoredSecretRepository;
use App\Repository\MembershipRepository;
use App\Repository\AuditLogRepository;
use App\Domain\AlertType;
use App\Notification\Alert;
use App\Notification\NotifierException;
use App\Repository\NotificationLogRepository;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\NotificationRateLimiter;
use App\Tests\Support\TestTranslator;
use DateTimeImmutable;
use Psr\Log\NullLogger;

/**
 * The dispatcher's two jobs: send it to the right places, and never send it
 * twice.
 */
final class NotificationDispatchTest extends NotificationTestCase
{
    private function alert(string $occurrence = '2026-10-01:7', int $subjectId = 42): Alert
    {
        return new Alert(
            AlertType::Renewal,
            Alert::SUBJECT_SUBSCRIPTION,
            $subjectId,
            $occurrence,
            'Netflix renews in 7 days',
            ['£10.99 is due on 1 Oct 2026.'],
        );
    }

    public function testAnAlertIsDeliveredOnce(): void
    {
        $this->addChannel($this->alice);

        $sent = $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);

        self::assertSame(1, $sent);
        self::assertSame(['Netflix renews in 7 days'], $this->notifier->titles());
    }

    public function testTheSameOccurrenceIsNeverSentTwice(): void
    {
        // The property the whole scheduler depends on: it runs daily, sees the
        // same due renewal every day until the date passes, and must say
        // something exactly once.
        $this->addChannel($this->alice);

        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);

        self::assertCount(1, $this->notifier->sent);
    }

    public function testADifferentLeadTimeIsADifferentOccurrence(): void
    {
        $this->addChannel($this->alice);

        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert('2026-10-01:30')]);
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert('2026-10-01:7')]);
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert('2026-10-01:1')]);

        self::assertCount(3, $this->notifier->sent);
    }

    public function testAMovedChargeDateIsANewOccurrence(): void
    {
        // Keying on the subscription alone would go silent forever after the
        // first reminder; keying on today's date would re-send every morning.
        // The charge date is what distinguishes a genuinely new event.
        $this->addChannel($this->alice);

        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert('2026-10-01:7')]);
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert('2026-11-05:7')]);

        self::assertCount(2, $this->notifier->sent);
    }

    public function testEachChannelGetsItsOwnCopyAndItsOwnLedgerEntry(): void
    {
        $this->addChannel($this->alice, 'Phone');
        $this->addChannel($this->alice, 'Desktop');

        self::assertSame(2, $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]));
        self::assertSame(0, $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]));
    }

    public function testOneUsersAlertDoesNotSuppressAnothers(): void
    {
        $this->addChannel($this->alice);
        $this->addChannel($this->bob);

        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);
        $this->dispatcher->dispatch($this->user($this->bob), [$this->alert()]);

        self::assertCount(2, $this->notifier->sent);
    }

    public function testAFailedDeliveryIsRetriedAndThenGivenUpOn(): void
    {
        // A webhook that is down for an hour should not cost the user the
        // alert — but an endpoint that has gone for good must not be hammered
        // for ever either.
        $this->addChannel($this->alice);
        $this->notifier->failNext();

        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);

        self::assertSame(3, $this->notifier->failures, 'three attempts, then no more');

        $entries = $this->log->findRecentForUser($this->alice);
        self::assertSame(NotificationLogRepository::STATUS_FAILED, $entries[0]['status']);
        self::assertSame(3, (int) $entries[0]['attempts']);
    }

    public function testASuccessfulRetryAfterAFailureStillOnlyDeliversOnce(): void
    {
        $this->addChannel($this->alice);
        $this->notifier->failNext();
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);

        $this->notifier->failNext(false);
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);

        self::assertCount(1, $this->notifier->sent);
    }

    public function testAFailureIsRecordedAgainstTheChannelSoTheUserCanSeeIt(): void
    {
        $id = $this->addChannel($this->alice);
        $this->notifier->failNext();

        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);

        $channel = $this->channels->find($this->alice, $id);
        self::assertNotNull($channel);
        self::assertNotNull($channel->lastError);
        self::assertStringContainsString('deliberately unavailable', $channel->lastError);
        self::assertNull($channel->lastSuccessAt);
    }

    public function testASuccessClearsAPreviousError(): void
    {
        $id = $this->addChannel($this->alice);
        $this->notifier->failNext();
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert('2026-10-01:30')]);

        $this->notifier->failNext(false);
        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert('2026-10-01:7')]);

        $channel = $this->channels->find($this->alice, $id);
        self::assertNotNull($channel);
        self::assertNull($channel->lastError);
        self::assertNotNull($channel->lastSuccessAt);
    }

    public function testAChannelStillSendsAfterTheKeyIsRotatedAndTheOldOneRemoved(): void
    {
        $id = $this->addChannel($this->alice, 'Phone');
        $old = TestKeys::ring()->current();

        (new SecretsRotationService(
            new StoredSecretRepository($this->db),
            new KeyRing('a-brand-new-key', [$old]),
            $this->db,
            new AuditLogService(
                new AuditLogRepository($this->db),
                new MembershipRepository($this->db),
                new RequestContextHolder(RequestContext::of('127.0.0.1', 'console')),
                $this->clock,
                new NullLogger(),
            ),
        ))->rotate();
        $this->useChannelKeys(new KeyRing('a-brand-new-key'));

        self::assertSame(1, $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]));
        self::assertSame($id, $this->notifier->sent[0]['channel']->id);
        self::assertSame(['target' => 'Phone'], $this->notifier->sent[0]['channel']->config);
    }

    public function testAChannelNoKeyOpensSendsNothingAndTheAlertWaitsForTheKey(): void
    {
        $this->addChannel($this->alice, 'Phone');
        $this->useChannelKeys(new KeyRing('a-key-the-channel-was-not-written-under'));

        self::assertSame(0, $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]));
        self::assertSame([], $this->notifier->sent);
        self::assertStringContainsString(
            'cannot be read',
            (string) $this->channels->findAllForUser($this->alice)[0]->lastError,
        );

        $this->useChannelKeys(TestKeys::ring());

        self::assertSame(1, $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]));
    }

    public function testATestMessageToAChannelNoKeyOpensIsRefused(): void
    {
        $id = $this->addChannel($this->alice, 'Phone');
        $this->useChannelKeys(new KeyRing('a-key-the-channel-was-not-written-under'));
        $channel = $this->channels->find($this->alice, $id);
        self::assertNotNull($channel);

        $this->expectException(NotifierException::class);
        $this->expectExceptionMessageMatches('/cannot be read/');
        $this->dispatcher->sendTest($this->user($this->alice), $channel);
    }

    public function testAnInactiveChannelReceivesNothing(): void
    {
        $id = $this->addChannel($this->alice);
        $this->notificationSettings->updateChannel(
            $this->user($this->alice),
            $id,
            ['label' => 'Phone', 'is_active' => '0'],
        );

        self::assertSame(0, $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]));
    }

    // ------------------------------------------------------------------
    // Routing
    // ------------------------------------------------------------------

    public function testWithNoRoutingEverythingGoesEverywhere(): void
    {
        $this->addChannel($this->alice, 'Phone');
        $this->addChannel($this->alice, 'Desktop');

        foreach (AlertType::all() as $type) {
            self::assertCount(2, $this->notificationSettings->channelsFor($this->alice, $type));
        }
    }

    public function testRoutingSendsEachAlertTypeOnlyWhereItWasAskedFor(): void
    {
        $phone = $this->addChannel($this->alice, 'Phone');
        $desktop = $this->addChannel($this->alice, 'Desktop');

        $this->notificationSettings->saveRoutes($this->alice, [
            $phone => [AlertType::CancelBy->value],
            $desktop => [AlertType::Renewal->value, AlertType::BudgetExceeded->value],
        ]);

        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);

        self::assertCount(1, $this->notifier->sent);
        self::assertSame('Desktop', $this->notifier->sent[0]['channel']->label);
    }

    public function testAChannelMutedForEveryTypeStaysSilent(): void
    {
        // A channel with no rows of its own, in a user's otherwise-populated
        // routing, is one they have deliberately turned everything off for. It
        // must not fall back to "all".
        $phone = $this->addChannel($this->alice, 'Phone');
        $this->addChannel($this->alice, 'Desktop');

        $this->notificationSettings->saveRoutes($this->alice, [$phone => [AlertType::Renewal->value]]);

        $this->dispatcher->dispatch($this->user($this->alice), [$this->alert()]);

        self::assertCount(1, $this->notifier->sent);
        self::assertSame('Phone', $this->notifier->sent[0]['channel']->label);
    }

    // ------------------------------------------------------------------
    // Test messages
    // ------------------------------------------------------------------

    public function testATestMessageCanBeSentRepeatedly(): void
    {
        $id = $this->addChannel($this->alice);
        $channel = $this->channels->find($this->alice, $id);
        self::assertNotNull($channel);

        $this->dispatcher->sendTest($this->user($this->alice), $channel);
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-15 08:00:01'));
        $this->dispatcher->sendTest($this->user($this->alice), $channel);

        self::assertCount(2, $this->notifier->sent);
    }

    public function testTestMessagesAreRateLimitedLikeEverythingElse(): void
    {
        // The one path where a person, rather than the scheduler, decides when
        // a request leaves the server — and the destination is a URL they
        // chose. Its ledger key is deliberately loose (one per second), so the
        // limit is what stops it being an unmetered way to make this server
        // talk to somewhere of somebody's choosing.
        $limited = new NotificationDispatcher(
            $this->notificationSettings,
            $this->registry,
            $this->log,
            $this->channels,
            new NotificationRateLimiter($this->log, $this->clock, 3, 20),
            new NullLogger(),
            $this->clock,
            TestTranslator::create(),
        );

        $id = $this->addChannel($this->alice);
        $channel = $this->channels->find($this->alice, $id);
        self::assertNotNull($channel);

        $refusals = 0;
        for ($second = 0; $second < 6; $second++) {
            $this->clock->advanceTo(new DateTimeImmutable(sprintf('2026-09-15 08:00:%02d', $second)));

            try {
                $limited->sendTest($this->user($this->alice), $channel);
            } catch (NotifierException) {
                $refusals++;
            }
        }

        self::assertCount(3, $this->notifier->sent);
        self::assertSame(3, $refusals);
    }

    public function testATestMessageIgnoresRoutingBecauseItIsTestingTheChannel(): void
    {
        $id = $this->addChannel($this->alice);
        $this->notificationSettings->saveRoutes($this->alice, [$id => []]);

        $channel = $this->channels->find($this->alice, $id);
        self::assertNotNull($channel);

        $this->dispatcher->sendTest($this->user($this->alice), $channel);

        self::assertCount(1, $this->notifier->sent);
    }
}
