<?php

declare(strict_types=1);

namespace App\Tests\Integration\Backup;

use App\Domain\AlertType;
use App\Domain\BackupDestinationType;
use App\Domain\BackupSchedule;
use App\Domain\Entity\BackupCopy;
use App\Domain\Entity\BackupRehearsal;
use App\Domain\Entity\BackupRun;
use App\I18n\CatalogLoader;
use App\I18n\LocaleContext;
use App\I18n\Locales;
use App\Repository\BackupCopyRepository;
use App\Repository\BackupDestinationRepository;
use App\Repository\BackupRehearsalRepository;
use App\Repository\BackupRunRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Security\KeyRing;
use App\Service\Backup\BackupAlertService;
use App\Service\Backup\BackupRetention;
use App\Tests\Integration\NotificationTestCase;
use App\Tests\Support\TestTranslator;
use DateTimeImmutable;
use Psr\Log\NullLogger;

/**
 * The backup alerts (Phase 39) reach instance administrators only, once per
 * incident, through their own routes and switches.
 */
final class BackupAlertTest extends NotificationTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->users->create('ivy@example.test', 'Ivy', 'hash', true);
        $this->instanceSettings->setBackupSchedule(BackupSchedule::Daily);
        // Alice is a household Owner but not an instance administrator.
        $this->addChannel($this->alice);
        $this->addChannel($this->admin);
    }

    public function testAFailedRunReachesInstanceAdministratorsOnlyAndOnce(): void
    {
        $this->failedRun('2026-09-15 03:00:00');

        $this->alerts()->notify();
        $this->alerts()->notify();

        self::assertSame([AlertType::BackupFailed->value], $this->notifier->alertTypes());
        self::assertSame($this->admin, $this->notifier->sent[0]['user']->id);
        self::assertSame('An instance backup failed', $this->notifier->sent[0]['alert']->title);
        self::assertStringContainsString('It could not be written', $this->notifier->sent[0]['alert']->body());
        self::assertSame(
            'https://renovo.example/settings/instance#instance-backups',
            $this->notifier->sent[0]['alert']->url,
        );
    }

    public function testARunNoDestinationWouldTakeIsAnIncidentAndOneThatReachedOneIsNot(): void
    {
        $nas = $this->destination('NAS');
        $cloud = $this->destination('Cloud');
        $lost = $this->verifiedRun('2026-09-14 03:00:00');
        $this->copy($lost, $nas, BackupCopy::FAILED);
        $this->copy($lost, $cloud, BackupCopy::FAILED);
        $partial = $this->verifiedRun('2026-09-15 03:00:00');
        $this->copy($partial, $nas, BackupCopy::FAILED);
        $this->copy($partial, $cloud, BackupCopy::CONFIRMED);

        $this->alerts()->notify();

        self::assertCount(1, $this->notifier->sent);
        self::assertSame('A backup could not be sent off the server', $this->notifier->sent[0]['alert']->title);
        self::assertStringContainsString('any of its 2 destinations', $this->notifier->sent[0]['alert']->body());
    }

    public function testACopyWaitingOnARemovedDestinationDoesNotHideTheFailure(): void
    {
        $nas = $this->destination('NAS');
        $gone = $this->destination('Gone');
        $run = $this->verifiedRun('2026-09-15 03:00:00');
        $this->copy($run, $nas, BackupCopy::FAILED);
        (new BackupCopyRepository($this->db))->queue($run, $gone, 'renovo-instance-' . $run);
        $this->destinationRepository()->delete($gone);

        $this->alerts()->notify();

        self::assertSame(['A backup could not be sent off the server'], $this->notifier->titles());
    }

    public function testStaleAfterTwoPeriodsOnceAndNotOnRecovery(): void
    {
        $nas = $this->destination('NAS');
        $run = $this->verifiedRun('2026-09-14 03:00:00');
        $this->copy($run, $nas, BackupCopy::CONFIRMED, '2026-09-14 03:05:00');

        $this->alerts()->notify();
        self::assertSame([], $this->notifier->sent, 'one day is not stale');

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-16 04:00:00'));
        $this->alerts()->notify();
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-17 04:00:00'));
        $this->alerts()->notify();
        self::assertSame([AlertType::BackupStale->value], $this->notifier->alertTypes());
        self::assertSame($this->admin, $this->notifier->sent[0]['user']->id);
        self::assertStringContainsString('since 14 Sep 2026 03:05 UTC', $this->notifier->sent[0]['alert']->body());

        // A copy arrives: nothing is said, and the next gap is a new incident.
        $next = $this->verifiedRun('2026-09-17 05:00:00');
        $this->copy($next, $nas, BackupCopy::CONFIRMED, '2026-09-17 05:05:00');
        $this->alerts()->notify();
        self::assertCount(1, $this->notifier->sent);

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-20 06:00:00'));
        $this->alerts()->notify();
        self::assertCount(2, $this->notifier->sent);
    }

    public function testNothingIsStaleWithoutADestinationOrASchedule(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-15 08:00:00'));
        self::assertFalse($this->alerts()->isStale());

        $this->destination('NAS', '2026-09-01 00:00:00');
        self::assertTrue($this->alerts()->isStale());

        $this->instanceSettings->setBackupSchedule(BackupSchedule::Off);
        self::assertFalse($this->alerts()->isStale());
    }

    public function testAFailedRehearsalNamesItsStep(): void
    {
        $rehearsals = new BackupRehearsalRepository($this->db);
        $id = $rehearsals->start(null, 'NAS', BackupRehearsal::KIND_RESTORE, $this->clock->now());
        $rehearsals->finish(
            $id,
            BackupRehearsal::FAILED,
            BackupRehearsal::KIND_RESTORE,
            'restoring',
            'x',
            $this->clock->now(),
        );

        $this->alerts()->notify();
        $this->alerts()->notify();

        self::assertSame([AlertType::BackupRehearsalFailed->value], $this->notifier->alertTypes());
        self::assertSame($this->admin, $this->notifier->sent[0]['user']->id);
        self::assertStringContainsString(
            'Rehearsing a restore from NAS failed while it was restoring.',
            $this->notifier->sent[0]['alert']->body(),
        );
    }

    public function testAnAdministratorWhoTurnedATypeOffIsNotSentIt(): void
    {
        $this->notificationSettings->savePreferences($this->admin, [
            'lead_days' => ['30', '7', '1'],
            'digest_mode' => 'immediate',
            'digest_day' => '1',
            'backup_failed_alerts' => '0',
        ]);
        $this->failedRun('2026-09-15 03:00:00');

        $this->alerts()->notify();

        self::assertSame([], $this->notifier->sent);
        self::assertFalse((new NotificationPreferenceRepository($this->db, $this->clock))
            ->findForUser($this->admin)->backupFailedAlerts);
    }

    public function testADisabledAdministratorIsSkippedAndAnOldIncidentIsForgotten(): void
    {
        $this->users->setDisabledAt($this->admin, $this->clock->now());
        $this->failedRun('2026-09-15 03:00:00');
        $this->alerts()->notify();
        self::assertSame([], $this->notifier->sent);

        $this->users->setDisabledAt($this->admin, null);
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-25 08:00:00'));
        $this->alerts()->notify();
        self::assertSame([], $this->notifier->sent);
    }

    public function testTheTypesAreOnlyInAnAdministratorsRoutingGrid(): void
    {
        self::assertContains(AlertType::BackupStale, AlertType::forUser(true));
        self::assertNotContains(AlertType::BackupStale, AlertType::forUser(false));
        self::assertFalse(AlertType::BackupFailed->usesLeadTimes());
    }

    private function alerts(): BackupAlertService
    {
        return new BackupAlertService(
            $this->users,
            new BackupRunRepository($this->db),
            new BackupCopyRepository($this->db),
            $this->destinationRepository(),
            new BackupRehearsalRepository($this->db),
            new NotificationPreferenceRepository($this->db, $this->clock),
            $this->dispatcher,
            $this->instanceSettings,
            new LocaleContext('en_GB'),
            new Locales(new CatalogLoader(dirname(__DIR__, 3) . '/translations'), 'en_GB'),
            TestTranslator::create(),
            $this->clock,
            new NullLogger(),
            'https://renovo.example',
        );
    }

    private function failedRun(string $at): int
    {
        $runs = new BackupRunRepository($this->db);
        $id = (int) $runs->claim(new DateTimeImmutable($at), BackupRun::TRIGGER_SCHEDULE);
        $runs->finish($id, BackupRun::FAILED, new DateTimeImmutable($at), ['error' => 'write_failed']);

        return $id;
    }

    private function verifiedRun(string $at): int
    {
        $runs = new BackupRunRepository($this->db);
        $id = (int) $runs->claim(new DateTimeImmutable($at), BackupRun::TRIGGER_SCHEDULE);
        $runs->finish($id, BackupRun::VERIFIED, new DateTimeImmutable($at), ['file' => 'renovo-instance-x']);

        return $id;
    }

    private function copy(int $run, int $destination, string $status, ?string $uploadedAt = null): void
    {
        $copies = new BackupCopyRepository($this->db);
        $copies->queue($run, $destination, 'renovo-instance-' . $run);
        $copy = array_values(array_filter(
            $copies->forRuns([$run])[$run],
            static fn (BackupCopy $c): bool => $c->destinationId === $destination,
        ))[0];

        match ($status) {
            BackupCopy::CONFIRMED => $copies->confirm($copy->id, new DateTimeImmutable($uploadedAt ?? 'now')),
            default => $copies->recordFailure($copy->id, $this->clock->now(), 'connect: down', true),
        };
    }

    private function destination(string $name, ?string $createdAt = null): int
    {
        $id = $this->destinationRepository()->create(
            BackupDestinationType::Directory,
            $name,
            ['path' => '/mnt/' . strtolower($name)],
            [],
            true,
            new BackupRetention(7, 4, 6),
        );
        $this->db->execute(
            'UPDATE backup_destinations SET created_at = :at WHERE id = :id',
            ['at' => $createdAt ?? $this->clock->now()->format('Y-m-d H:i:s'), 'id' => $id],
        );

        return $id;
    }

    private function destinationRepository(): BackupDestinationRepository
    {
        return new BackupDestinationRepository($this->db, $this->clock, new KeyRing('alert-test-key'));
    }
}
