<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Repository\AttachmentRepository;
use App\Repository\HouseholdRepository;
use App\Repository\SplitRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\AttachmentService;
use App\Service\AttachmentStorage;
use App\Service\AuditLogService;
use App\Service\ValidationException;
use App\Support\FrozenClock;
use App\Tests\Support\FakeUpload;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use App\Security\RequestContextHolder;

/**
 * Invoices and receipts: what is accepted, where it goes, and who can read it.
 *
 * The two claims that matter are that a file's type is decided by its contents
 * rather than its name, and that an attachment is exactly as visible as the
 * subscription it belongs to — no more, and no less.
 */
final class AttachmentTest extends DatabaseTestCase
{
    private AttachmentService $attachments;
    private AttachmentStorage $storage;
    private string $directory;

    private Scope $ownerScope;
    private Scope $otherMemberScope;
    private Scope $outsiderScope;

    private int $subscriptionId;
    private \App\Domain\Entity\User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/renovo-attachments-' . bin2hex(random_bytes(6));
        $this->storage = new AttachmentStorage($this->directory, 1024 * 1024);

        $clock = new FrozenClock(new DateTimeImmutable('2026-06-01 09:00:00'));
        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $clock->now());
        $otherId = $users->create('other@example.test', 'Other', 'hash', false, $clock->now());
        $outsiderId = $users->create('outsider@example.test', 'Outsider', 'hash', false, $clock->now());

        $householdId = $households->create('Household', $ownerId);
        $memberships->create($householdId, $ownerId, Role::OwnerAdmin);
        $memberships->create($householdId, $otherId, Role::Editor);

        $otherHouseholdId = $households->create('Elsewhere', $outsiderId);
        $memberships->create($otherHouseholdId, $outsiderId, Role::OwnerAdmin);

        $this->owner = $users->findById($ownerId);

        // ISOLATED throughout: it is the mode in which an attachment could leak
        // to somebody who cannot see its subscription, so it is the mode worth
        // testing.
        $this->ownerScope = Scope::forMember($ownerId, false, $householdId, Role::OwnerAdmin, IsolationMode::Isolated);
        $this->otherMemberScope = Scope::forMember(
            $otherId,
            false,
            $householdId,
            Role::Editor,
            IsolationMode::Isolated,
        );
        $this->outsiderScope = Scope::forMember(
            $outsiderId,
            false,
            $otherHouseholdId,
            Role::OwnerAdmin,
            IsolationMode::Isolated,
        );

        $subscriptions = new SubscriptionRepository($this->db);
        $this->subscriptionId = $subscriptions->create($this->ownerScope, [
            'name' => 'Broadband',
            'price_minor' => 3500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-07-01',
            'is_active' => true,
        ], []);

        $this->attachments = new AttachmentService(
            new AttachmentRepository($this->db, $clock),
            $subscriptions,
            $this->storage,
            new AuditLogService(
                new \App\Repository\AuditLogRepository($this->db),
                $memberships,
                new RequestContextHolder(),
                $clock,
                new NullLogger(),
            ),
            new \App\Repository\ChargeRepository($this->db),
        );
    }

    protected function tearDown(): void
    {
        FakeUpload::cleanUp();
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function testAPdfIsStoredOutsideTheWebRootUnderAGeneratedName(): void
    {
        $id = $this->attachments->upload(
            $this->ownerScope,
            $this->owner,
            $this->subscriptionId,
            FakeUpload::pdf('March invoice.pdf'),
            '2026-03-01',
        );

        $attachment = $this->attachments->find($this->ownerScope, $id);

        self::assertNotNull($attachment);
        self::assertSame('application/pdf', $attachment->mimeType);
        self::assertSame('March invoice.pdf', $attachment->originalFilename);
        self::assertSame('2026-03-01', $attachment->periodDate?->format('Y-m-d'));

        // The stored name is chosen by the application, never by the client.
        self::assertMatchesRegularExpression(
            '#^[0-9]+/[0-9a-f]{32}\.pdf$#',
            $attachment->storedPath,
        );

        $absolute = $this->attachments->absolutePath($attachment);
        self::assertNotNull($absolute);
        self::assertFileExists($absolute);
        self::assertStringStartsWith($this->directory, $absolute);
    }

    public function testAnInvoiceFiledAgainstAPaymentBelongsToItAndItsDate(): void
    {
        $charges = new \App\Repository\ChargeRepository($this->db);
        $chargeId = $charges->record(
            $this->ownerScope,
            $this->subscriptionId,
            $this->owner->id,
            new DateTimeImmutable('2026-05-01'),
            \App\Domain\Money::of(3500, 'GBP'),
            \App\Domain\ChargeSource::CatchUp,
        );

        $id = $this->attachments->upload(
            $this->ownerScope,
            $this->owner,
            $this->subscriptionId,
            FakeUpload::pdf('May.pdf'),
            '2026-01-01',
            $chargeId,
        );

        $attachment = $this->attachments->find($this->ownerScope, $id);
        self::assertSame($chargeId, $attachment?->chargeId);
        // The payment's own date, whatever the form said.
        self::assertSame('2026-05-01', $attachment->periodDate?->format('Y-m-d'));
        $byCharge = $this->attachments->byCharge($this->ownerScope, $this->subscriptionId);
        self::assertSame([$chargeId], array_keys($byCharge));
    }

    public function testAnInvoiceCannotBeFiledAgainstAnotherSubscriptionsPayment(): void
    {
        $subscriptions = new SubscriptionRepository($this->db);
        $otherId = $subscriptions->create($this->ownerScope, [
            'name' => 'Water',
            'price_minor' => 2000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-07-01',
            'is_active' => true,
        ], []);
        $chargeId = (new \App\Repository\ChargeRepository($this->db))->record(
            $this->ownerScope,
            $otherId,
            $this->owner->id,
            new DateTimeImmutable('2026-05-01'),
            \App\Domain\Money::of(2000, 'GBP'),
            \App\Domain\ChargeSource::CatchUp,
        );

        try {
            $this->attachments->upload(
                $this->ownerScope,
                $this->owner,
                $this->subscriptionId,
                FakeUpload::pdf('Wrong.pdf'),
                null,
                $chargeId,
            );
            self::fail('Filed an invoice against another subscription\'s payment.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('charge_id', $exception->errors());
        }

        self::assertSame([], $this->filesUnder($this->directory));
    }

    public function testAFileThatIsNotWhatItClaimsIsRejected(): void
    {
        // Named .pdf, declared application/pdf, and full of PHP. The magic
        // bytes are the only thing consulted.
        $this->expectException(ValidationException::class);

        $this->attachments->upload(
            $this->ownerScope,
            $this->owner,
            $this->subscriptionId,
            FakeUpload::disguisedScript('invoice.pdf'),
        );
    }

    public function testNothingIsWrittenWhenAnUploadIsRejected(): void
    {
        try {
            $this->attachments->upload(
                $this->ownerScope,
                $this->owner,
                $this->subscriptionId,
                FakeUpload::disguisedScript(),
            );
            self::fail('The upload should have been rejected.');
        } catch (ValidationException) {
            // Expected.
        }

        self::assertSame([], $this->attachments->forSubscription($this->ownerScope, $this->subscriptionId));
        self::assertSame([], $this->filesUnder($this->directory));
    }

    public function testAFileOverTheSizeLimitIsRejected(): void
    {
        $storage = new AttachmentStorage($this->directory, 16);

        $this->expectException(ValidationException::class);

        $storage->store(FakeUpload::pdf(), 1);
    }

    public function testAnAttachmentIsInvisibleToAMemberWhoCannotSeeItsSubscription(): void
    {
        $id = $this->attachments->upload(
            $this->ownerScope,
            $this->owner,
            $this->subscriptionId,
            FakeUpload::pdf(),
        );

        // Same household, ISOLATED mode, does not own the subscription.
        self::assertNull($this->attachments->find($this->otherMemberScope, $id));
        self::assertSame([], $this->attachments->forSubscription($this->otherMemberScope, $this->subscriptionId));

        // A different household entirely.
        self::assertNull($this->attachments->find($this->outsiderScope, $id));
    }

    public function testAnotherHouseholdCannotDeleteAnAttachment(): void
    {
        $id = $this->attachments->upload(
            $this->ownerScope,
            $this->owner,
            $this->subscriptionId,
            FakeUpload::pdf(),
        );

        self::assertFalse($this->attachments->delete($this->outsiderScope, $this->owner, $id));

        // Still there, and still readable by the person it belongs to.
        $attachment = $this->attachments->find($this->ownerScope, $id);
        self::assertNotNull($attachment);
        self::assertFileExists((string) $this->attachments->absolutePath($attachment));
    }

    public function testDeletingRemovesBothTheRowAndTheFile(): void
    {
        $id = $this->attachments->upload(
            $this->ownerScope,
            $this->owner,
            $this->subscriptionId,
            FakeUpload::pdf(),
        );

        $attachment = $this->attachments->find($this->ownerScope, $id);
        self::assertNotNull($attachment);
        $path = (string) $this->attachments->absolutePath($attachment);

        self::assertTrue($this->attachments->delete($this->ownerScope, $this->owner, $id));
        self::assertNull($this->attachments->find($this->ownerScope, $id));
        self::assertFileDoesNotExist($path);
    }

    /**
     * The discriminating case for who may attach a document.
     *
     * Read access is wider than write access by exactly one case: a member
     * listed on a shared-cost split can *see* a subscription they do not own.
     * Had seeing it been enough to file a receipt against it, the scoping
     * layer would have stamped the new row with the uploader's id in ISOLATED
     * mode — leaving an invoice that the person whose bill it documents cannot
     * see, attached to their subscription. Requiring write access removes the
     * case rather than compensating for it.
     */
    public function testASplitParticipantWhoCannotWriteTheSubscriptionCannotAttachToIt(): void
    {
        // As SplitService does: the mode first, then the participants. Only a
        // live split widens a read (K156).
        (new SubscriptionRepository($this->db))
            ->setSplitMode($this->ownerScope, $this->subscriptionId, SplitMode::Equal);
        $splits = new SplitRepository($this->db);
        $splits->replaceForSubscription(
            $this->ownerScope,
            $this->subscriptionId,
            $this->ownerScope->userId,
            [
                ['user_id' => $this->ownerScope->userId, 'share_units' => 1],
                ['user_id' => $this->otherMemberScope->userId, 'share_units' => 1],
            ],
        );

        // The participant really can see it — otherwise this test would prove
        // nothing about which predicate is consulted.
        $subscriptions = new SubscriptionRepository($this->db);
        self::assertNotNull(
            $subscriptions->find($this->otherMemberScope, $this->subscriptionId),
            'A split participant is expected to be able to read the subscription.',
        );
        self::assertFalse($subscriptions->isWritable($this->otherMemberScope, $this->subscriptionId));

        $this->expectException(ValidationException::class);

        $this->attachments->upload(
            $this->otherMemberScope,
            $this->owner,
            $this->subscriptionId,
            FakeUpload::pdf(),
        );
    }

    public function testAStoredPathThatTriesToEscapeTheDirectoryResolvesToNothing(): void
    {
        // Defence for a path that arrived from somewhere other than store() —
        // a restored archive, a hand-edited row, a bug elsewhere.
        foreach (
            [
            '../../../etc/passwd',
            '1/../../etc/passwd',
            '/etc/passwd',
            '1/not-a-hex-name.pdf',
            '1/' . str_repeat('a', 32) . '.php',
            ] as $path
        ) {
            self::assertNull(
                $this->storage->absolutePath($path),
                sprintf('"%s" must not resolve to a readable file.', $path),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $found = [];
        foreach ((array) glob($directory . '/*/*') as $path) {
            if (is_string($path) && is_file($path)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach ((array) glob($directory . '/*') as $path) {
            if (!is_string($path)) {
                continue;
            }

            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
