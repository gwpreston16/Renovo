<?php

declare(strict_types=1);

namespace App\Tests\Integration\Backup;

use App\Domain\BackupDestinationType;
use App\Domain\Entity\BackupCopy;
use App\Domain\Entity\BackupRun;
use App\Repository\BackupCopyRepository;
use App\Repository\BackupDestinationRepository;
use App\Repository\BackupRunRepository;
use App\Security\KeyRing;
use App\Service\Backup\BackupRetention;
use App\Service\Backup\Destination\BackupDestinationFactory;
use App\Service\Backup\Destination\HttpExchange;
use App\Service\Backup\OffsiteBackupService;
use App\Http\GuardedTransferClient;
use App\Http\HttpClient;
use App\Http\HttpClientOptions;
use App\Http\StaticTrustedTargets;
use App\Http\UrlGuard;
use App\Support\FrozenClock;
use App\Tests\Support\FakeDnsResolver;
use App\Tests\Support\LocalServer;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\CapturingLogger;
use DateTimeImmutable;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Sending verified backups to destinations (Phase 39): one attempt per pass,
 * three in all, retention per destination with its guards, and nothing
 * deleted that Renovo did not upload.
 *
 * Directory destinations stand in for every kind here; each kind's own
 * transfer is tested against real storage in `BackupDestinationsTest`.
 */
final class OffsiteBackupTest extends DatabaseTestCase
{
    private FrozenClock $clock;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-23 03:00:00'));
        $this->root = sys_get_temp_dir() . '/renovo-offsite-' . bin2hex(random_bytes(6));
        foreach (['backups', 'nas', 'second'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($this->root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            chmod($directory, 0o700);
            rmdir($directory);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testAVerifiedRunGoesToEveryEnabledDestinationAndIsConfirmed(): void
    {
        $nas = $this->destination('NAS', $this->root . '/nas');
        $second = $this->destination('Second', $this->root . '/second');
        $off = $this->destination('Off', $this->root . '/second', false);
        $run = $this->verifiedRun(0);

        self::assertSame(2, $this->service()->queue($run));
        $totals = $this->service()->sendPending();

        self::assertSame(2, $totals['confirmed']);
        foreach ([$nas, $second] as $destination) {
            self::assertFileEquals(
                $this->root . '/backups/' . $run->file,
                $this->root . '/' . ($destination === $nas ? 'nas' : 'second') . '/' . $run->file,
            );
        }
        $copies = $this->copies()->forRuns([$run->id])[$run->id];
        self::assertCount(2, $copies);
        self::assertTrue($copies[0]->isConfirmed() && $copies[1]->isConfirmed());
        self::assertNotContains($off, array_map(static fn (BackupCopy $copy): ?int => $copy->destinationId, $copies));
        self::assertTrue($this->service()->hasOffsiteCopy());
        self::assertArrayHasKey($nas, $this->service()->lastUploads());
    }

    public function testAFailingUploadIsRetriedOnEachPassThenMarkedFailedAndTheRunStaysVerified(): void
    {
        $broken = $this->destination('Gone', $this->root . '/missing');
        $run = $this->verifiedRun(0);
        $this->service()->queue($run);

        foreach ([1, 2] as $pass) {
            self::assertSame(1, $this->service()->sendPending()['retrying'], 'pass ' . $pass);
            // The next scheduler pass is a day later, past the lease.
            $this->clock->advanceTo($this->clock->now()->modify('+1 day'));
        }
        self::assertSame(1, $this->service()->sendPending()['failed']);
        $this->clock->advanceTo($this->clock->now()->modify('+1 day'));
        self::assertSame(0, array_sum($this->service()->sendPending()));

        $copy = $this->copies()->forRuns([$run->id])[$run->id][0];
        self::assertSame(BackupCopy::FAILED, $copy->status);
        self::assertSame(3, $copy->attempts);
        self::assertSame($broken, $copy->destinationId);
        self::assertStringStartsWith('connect:', (string) $copy->error);
        self::assertSame(BackupRun::VERIFIED, $this->runs()->find($run->id)?->status);
    }

    public function testACredentialIsInNoLogNoCopyErrorAndNoAuditEntryWhenAnUploadFails(): void
    {
        $canary = 'canary-dav-password-0c4f';
        $server = new LocalServer('webdav.php', [
            'WEBDAV_ROOT' => $this->root . '/nas',
            'WEBDAV_USER' => 'backup',
            'WEBDAV_PASSWORD' => 'the-real-password',
        ]);
        try {
            (new BackupDestinationRepository($this->db, $this->clock, $this->keys()))->create(
                BackupDestinationType::WebDav,
                'Nextcloud',
                ['url' => $server->url('/dav/', 'nas.test'), 'username' => 'backup'],
                ['password' => $canary],
                true,
                new BackupRetention(7, 4, 6),
            );
            $run = $this->verifiedRun(0);
            $logger = new CapturingLogger();
            $service = $this->service($logger, ['nas.test']);
            $service->queue($run);

            self::assertSame(1, $service->sendPending()['retrying']);
        } finally {
            $server->stop();
        }

        $copy = $this->copies()->forRuns([$run->id])[$run->id][0];
        self::assertStringStartsWith('write:', (string) $copy->error);
        self::assertNotEmpty($logger->lines);
        $audit = (string) json_encode($this->db->fetchAll('SELECT * FROM audit_log'));
        foreach ([implode("\n", $logger->lines), (string) $copy->error, $audit] as $text) {
            self::assertStringNotContainsString($canary, $text);
            self::assertStringNotContainsString(base64_encode('backup:' . $canary), $text);
        }
    }

    public function testACopyBeingSentIsNotTakenByASecondPass(): void
    {
        $this->destination('NAS', $this->root . '/nas');
        $run = $this->verifiedRun(0);
        $this->service()->queue($run);
        $copy = $this->copies()->pending()[0];
        self::assertFalse($this->service()->isSending());

        self::assertTrue($this->copies()->claim($copy->id, $this->clock->now(), 3600));
        self::assertTrue($this->service()->isSending());

        self::assertSame(0, array_sum($this->service()->sendPending()));
        self::assertFileDoesNotExist($this->root . '/nas/' . $run->file);
    }

    public function testSendingOneRunLeavesTheOthersToTheScheduler(): void
    {
        $this->destination('NAS', $this->root . '/nas');
        $older = $this->verifiedRun(1);
        $newer = $this->verifiedRun(0);
        $this->service()->queue($older);
        $this->service()->queue($newer);

        self::assertSame(1, $this->service()->sendPending($newer->id)['confirmed']);

        self::assertFileExists($this->root . '/nas/' . $newer->file);
        self::assertFileDoesNotExist($this->root . '/nas/' . $older->file);
        self::assertSame($older->id, $this->copies()->pending()[0]->runId);
    }

    public function testADisabledDestinationWaitsWithoutSpendingAnAttempt(): void
    {
        $nas = $this->destination('NAS', $this->root . '/nas');
        $run = $this->verifiedRun(0);
        $this->service()->queue($run);
        (new BackupDestinationRepository($this->db, $this->clock, $this->keys()))->setEnabled($nas, false);

        $this->service()->sendPending();

        self::assertSame(0, $this->copies()->pending()[0]->attempts);
    }

    public function testABackupPrunedLocallyBeforeItWasSentIsGivenUpOn(): void
    {
        $this->destination('NAS', $this->root . '/nas');
        $run = $this->verifiedRun(0);
        $this->service()->queue($run);
        unlink($this->root . '/backups/' . $run->file);

        self::assertSame(1, $this->service()->sendPending()['failed']);
    }

    public function testEachDestinationKeepsItsOwnRetentionAndTheNewestSurvivesZero(): void
    {
        $keepNone = $this->destination('Keep none', $this->root . '/nas', true, new BackupRetention(0, 0, 0));
        $keepThree = $this->destination('Keep three', $this->root . '/second', true, new BackupRetention(3, 0, 0));
        // Not Renovo's: never deleted, whatever the policy.
        file_put_contents($this->root . '/nas/renovo-instance-20200101-000000-aaaaaa.renovo-backup', 'someone else');
        file_put_contents($this->root . '/nas/holiday.jpg', 'someone else');

        $files = [];
        for ($day = 5; $day >= 0; $day--) {
            $run = $this->verifiedRun($day);
            $files[] = $run->file;
            $this->service()->queue($run);
            $this->service()->sendPending();
        }

        self::assertSame(
            [
                'holiday.jpg',
                'renovo-instance-20200101-000000-aaaaaa.renovo-backup',
                $files[5],
            ],
            $this->listing('nas'),
        );
        self::assertSame([$files[3], $files[4], $files[5]], $this->listing('second'));

        self::assertCount(1, $this->copies()->confirmedAt($keepNone));
        self::assertCount(3, $this->copies()->confirmedAt($keepThree));
    }

    public function testADestinationWhoseCredentialsCannotBeOpenedIsNotSentTo(): void
    {
        (new BackupDestinationRepository($this->db, $this->clock, new KeyRing('another-key')))->create(
            BackupDestinationType::WebDav,
            'Locked',
            ['url' => 'https://dav.example/', 'username' => 'me'],
            ['password' => 'pw'],
            true,
            new BackupRetention(7, 4, 6),
        );
        $run = $this->verifiedRun(0);
        $this->service()->queue($run);

        self::assertSame(1, $this->service()->sendPending()['retrying']);
        self::assertStringStartsWith('connect:', (string) $this->copies()->pending()[0]->error);
    }

    public function testRetentionDoesNotRunWhenNothingNewWasConfirmed(): void
    {
        $nas = $this->destination('NAS', $this->root . '/nas');
        foreach ([2, 1] as $day) {
            $run = $this->verifiedRun($day);
            $this->service()->queue($run);
            $this->service()->sendPending();
        }

        // The policy tightens, but no upload has been confirmed since.
        $repository = new BackupDestinationRepository($this->db, $this->clock, $this->keys());
        $record = $repository->find($nas);
        self::assertNotNull($record);
        $repository->update($nas, 'NAS', $record->config, null, new BackupRetention(0, 0, 0));
        $broken = $this->verifiedRun(0);
        $this->service()->queue($broken);
        unlink($this->root . '/backups/' . $broken->file);
        $this->service()->sendPending();

        self::assertCount(2, $this->listing('nas'));
    }

    /**
     * @return list<string>
     */
    private function listing(string $directory): array
    {
        $files = array_values(array_diff((array) scandir($this->root . '/' . $directory), ['.', '..']));
        sort($files);

        return array_map('strval', $files);
    }

    private function verifiedRun(int $daysAgo): BackupRun
    {
        $at = $this->clock->now()->modify(sprintf('-%d days', $daysAgo));
        $file = sprintf('renovo-instance-%s-%06x.renovo-backup', $at->format('Ymd-His'), $daysAgo + 1);
        $contents = 'encrypted backup from ' . $at->format('Y-m-d') . random_bytes(32);
        file_put_contents($this->root . '/backups/' . $file, $contents);

        $id = (int) $this->runs()->claim($at, BackupRun::TRIGGER_SCHEDULE);
        $this->runs()->finish($id, BackupRun::VERIFIED, $at, [
            'file' => $file,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ]);

        $run = $this->runs()->find($id);
        self::assertNotNull($run);

        return $run;
    }

    private function destination(
        string $name,
        string $path,
        bool $enabled = true,
        ?BackupRetention $retention = null,
    ): int {
        return (new BackupDestinationRepository($this->db, $this->clock, $this->keys()))->create(
            BackupDestinationType::Directory,
            $name,
            ['path' => $path],
            [],
            $enabled,
            $retention ?? new BackupRetention(7, 4, 6),
        );
    }

    /**
     * @param list<string> $trusted
     */
    private function service(?CapturingLogger $logger = null, array $trusted = []): OffsiteBackupService
    {
        return new OffsiteBackupService(
            $this->runs(),
            $this->copies(),
            new BackupDestinationRepository($this->db, $this->clock, $this->keys()),
            new BackupDestinationFactory(
                new HttpExchange(new GuardedTransferClient(
                    new HttpClient(new ResponseFactory(), new StreamFactory(), new HttpClientOptions()),
                    new UrlGuard(
                        new FakeDnsResolver(['nas.test' => ['127.0.0.1']]),
                        new StaticTrustedTargets($trusted),
                        new HttpClientOptions(),
                        new NullLogger(),
                    ),
                    (new HttpClientOptions())->forTransfers(60, 1024 * 1024 * 1024),
                )),
                new RequestFactory(),
                new StreamFactory(),
                $this->clock,
            ),
            $this->clock,
            $logger ?? new CapturingLogger(),
            $this->root . '/backups',
            3600,
        );
    }

    private function runs(): BackupRunRepository
    {
        return new BackupRunRepository($this->db);
    }

    private function copies(): BackupCopyRepository
    {
        return new BackupCopyRepository($this->db);
    }

    private function keys(): KeyRing
    {
        return new KeyRing('offsite-test-key');
    }
}
