<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\AttachmentService;
use App\Service\AttachmentStorage;
use App\Service\BackupService;
use App\Service\BudgetService;
use App\Service\CategoryService;
use App\Service\ChargeService;
use App\Service\LedgerBackfillRunner;
use App\Service\PaymentMethodService;
use App\Service\PriceHistoryService;
use App\Service\SubscriptionService;
use App\Service\ValidationException;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Support\FakeUpload;
use DateTimeImmutable;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use ZipArchive;

use function DI\autowire;
use function DI\factory;

/**
 * Export a household, restore it somewhere else, and compare.
 *
 * Fidelity is the point of a backup, so the test restores into a *different*
 * household with different user ids and asserts the result matches. Restoring
 * into the same one would pass even if the code were quietly relying on ids
 * that a real disaster-recovery run would not have.
 *
 * The other half is that the archive is treated as hostile. A backup file is
 * untrusted input however it was produced — it has been off the server, and
 * anybody can rename a zip.
 */
final class BackupRestoreTest extends DatabaseTestCase
{
    private ContainerInterface $container;
    private BackupService $backups;
    private SubscriptionService $subscriptions;
    private CategoryService $categories;
    private BudgetService $budgets;
    private AttachmentService $attachments;

    private Scope $source;
    private Scope $target;
    private \App\Domain\Entity\User $sourceUser;
    private \App\Domain\Entity\User $targetUser;

    private string $attachmentDirectory;
    private string $logoDirectory;
    private FrozenClock $clock;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-06-01 09:00:00'));
        $this->attachmentDirectory = sys_get_temp_dir() . '/renovo-backup-attach-' . bin2hex(random_bytes(6));
        $this->logoDirectory = sys_get_temp_dir() . '/renovo-backup-logos-' . bin2hex(random_bytes(6));

        $this->container = $this->buildContainer();
        $this->backups = $this->container->get(BackupService::class);
        $this->subscriptions = $this->container->get(SubscriptionService::class);
        $this->categories = $this->container->get(CategoryService::class);
        $this->budgets = $this->container->get(BudgetService::class);
        $this->attachments = $this->container->get(AttachmentService::class);

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        // The source household has two members, so owner remapping by email has
        // something to remap.
        $ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $this->clock->now());
        $partnerId = $users->create('partner@example.test', 'Partner', 'hash', false, $this->clock->now());
        $sourceHousehold = $households->create('Source household', $ownerId);
        $memberships->create($sourceHousehold, $ownerId, Role::OwnerAdmin);
        $memberships->create($sourceHousehold, $partnerId, Role::Editor);

        // The destination has the same two people — different user ids in a real
        // restore, but here the same, which is enough for the email lookup to be
        // doing the work rather than luck.
        $targetOwnerId = $users->create('new-owner@example.test', 'New owner', 'hash', false, $this->clock->now());
        $targetHousehold = $households->create('Target household', $targetOwnerId);
        $memberships->create($targetHousehold, $targetOwnerId, Role::OwnerAdmin);
        $memberships->create($targetHousehold, $partnerId, Role::Editor);

        $this->sourceUser = $users->findById($ownerId);
        $this->targetUser = $users->findById($targetOwnerId);

        $this->source = Scope::forMember($ownerId, false, $sourceHousehold, Role::OwnerAdmin, IsolationMode::Shared);
        $this->target = Scope::forMember(
            $targetOwnerId,
            false,
            $targetHousehold,
            Role::OwnerAdmin,
            IsolationMode::Shared,
        );

        $this->partnerId = $partnerId;
    }

    private int $partnerId = 0;

    protected function tearDown(): void
    {
        FakeUpload::cleanUp();

        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        $this->removeDirectory($this->attachmentDirectory);
        $this->removeDirectory($this->logoDirectory);

        parent::tearDown();
    }

    public function testASourceHouseholdSurvivesTheRoundTrip(): void
    {
        $categoryId = $this->categories->create($this->source, 'Entertainment', '#112233');

        $subscriptionId = $this->subscriptions->create($this->source, [
            'name' => 'Streaming',
            'price' => '10.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'start_date' => '2024-03-01',
            'notes' => 'Shared with the house',
            'category_id' => $categoryId,
            'owner_user_id' => $this->partnerId,
            'notice_period_amount' => '30',
            'notice_period_unit' => 'days',
            'tags' => 'video,family',
        ]);

        $this->subscriptions->create($this->source, [
            'name' => 'Annual insurance',
            'price' => '240.00',
            'currency' => 'EUR',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => '2027-03-01',
        ]);

        $this->budgets->create($this->source, [
            'name' => 'Fun money',
            'amount' => '50.00',
            'currency' => 'GBP',
            'period' => 'monthly',
            'category_id' => $categoryId,
            'warn_threshold_percent' => '80',
        ]);

        $this->attachments->upload(
            $this->source,
            $this->sourceUser,
            $subscriptionId,
            FakeUpload::pdf('December invoice.pdf'),
            '2026-12-01',
        );

        $archive = $this->export();

        $summary = $this->backups->restore($this->target, $this->targetUser, $archive);

        self::assertSame(2, $summary['subscriptions']);
        self::assertSame(1, $summary['categories']);
        self::assertSame(1, $summary['budgets']);
        self::assertSame(1, $summary['attachments']);
        self::assertSame(0, $summary['skipped']);

        $restored = [];
        foreach ($this->subscriptions->allForStats($this->target, activeOnly: false) as $subscription) {
            $restored[$subscription->name] = $subscription;
        }

        self::assertArrayHasKey('Streaming', $restored);
        self::assertArrayHasKey('Annual insurance', $restored);

        $streaming = $restored['Streaming'];
        self::assertSame(1099, $streaming->price->amountMinor);
        self::assertSame('GBP', $streaming->price->currency);
        self::assertSame('monthly', $streaming->billingCycle?->value);
        self::assertSame('2026-12-01', $streaming->nextPaymentDate?->format('Y-m-d'));
        self::assertSame('2024-03-01', $streaming->startDate?->format('Y-m-d'));
        self::assertSame('Shared with the house', $streaming->notes);
        self::assertSame('Entertainment', $streaming->categoryName);
        self::assertSame(30, $streaming->noticePeriod->amount);
        self::assertSame('days', $streaming->noticePeriod->unit);
        self::assertEqualsCanonicalizing(
            ['video', 'family'],
            array_map(static fn ($tag): string => $tag->name, $streaming->tags),
        );

        // The member who exists in both households keeps their rows.
        self::assertSame($this->partnerId, $streaming->ownerUserId);

        self::assertSame(24000, $restored['Annual insurance']->price->amountMinor);
        self::assertSame('EUR', $restored['Annual insurance']->price->currency);

        $budgets = $this->budgets->all($this->target, activeOnly: false);
        self::assertCount(1, $budgets);
        self::assertSame('Fun money', $budgets[0]->name);
        self::assertSame(5000, $budgets[0]->amount->amountMinor);
        self::assertSame(80, $budgets[0]->warnThresholdPercent);
        self::assertSame('Entertainment', $budgets[0]->categoryName);

        // The file came back, with its name and its contents.
        $attachments = $this->attachments->forSubscription($this->target, $streaming->id);
        self::assertCount(1, $attachments);
        self::assertSame('December invoice.pdf', $attachments[0]->originalFilename);
        self::assertSame('application/pdf', $attachments[0]->mimeType);
        self::assertSame('2026-12-01', $attachments[0]->periodDate?->format('Y-m-d'));

        $path = $this->attachments->absolutePath($attachments[0]);
        self::assertNotNull($path);
        self::assertStringStartsWith('%PDF', (string) file_get_contents($path));
    }

    public function testAMemberWhoIsNoLongerHereHandsTheirRowsToTheRestorer(): void
    {
        $this->subscriptions->create($this->source, [
            'name' => 'Partner only',
            'price' => '5.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'owner_user_id' => $this->partnerId,
        ]);

        // A third household, which the partner is not a member of.
        $elsewhere = $this->householdWithoutThePartner();

        $this->backups->restore($elsewhere, $this->targetUser, $this->export());

        $restored = $this->subscriptions->allForStats($elsewhere, activeOnly: false);

        self::assertCount(1, $restored);
        self::assertSame($elsewhere->userId, $restored[0]->ownerUserId);
    }

    public function testTheExportIsScopedToWhatTheExporterCanSee(): void
    {
        // The claim that makes a backup safe to offer to a household Owner: it
        // is built through the scoping layer, not by dumping tables.
        $this->subscriptions->create($this->target, [
            'name' => 'Not in the source household',
            'price' => '1.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        $data = $this->readArchiveJson($this->export(), 'data.json');

        self::assertSame([], $data['subscriptions']);
    }

    public function testAnArchiveWithAnEscapingEntryNameIsRefusedWholesale(): void
    {
        $this->subscriptions->create($this->source, [
            'name' => 'Streaming',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        $archive = $this->export();

        // The classic zip-slip payload, added to an otherwise valid backup.
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        $zip->addFromString('../../../../tmp/renovo-escaped.txt', 'pwned');
        $zip->close();

        try {
            $this->backups->restore($this->target, $this->targetUser, $archive);
            self::fail('An archive with an escaping entry name must be refused.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('file', $exception->errors());
        }

        // Refused wholesale: not one row of the otherwise-valid archive was
        // written, and nothing was extracted.
        self::assertSame([], $this->subscriptions->allForStats($this->target, activeOnly: false));
        self::assertFileDoesNotExist('/tmp/renovo-escaped.txt');
    }

    public function testAnArchiveThatIsNotABackupIsRefused(): void
    {
        $path = $this->temporary();
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode(['format' => 'something-else'], JSON_THROW_ON_ERROR));
        $zip->addFromString('data.json', '{}');
        $zip->close();

        $this->expectException(ValidationException::class);

        $this->backups->restore($this->target, $this->targetUser, $path);
    }

    public function testAFileThatIsNotAZipIsRefused(): void
    {
        $path = $this->temporary();
        file_put_contents($path, 'this is not a zip archive');

        $this->expectException(ValidationException::class);

        $this->backups->restore($this->target, $this->targetUser, $path);
    }

    public function testAnAttachmentInTheArchiveIsRevalidatedByItsContents(): void
    {
        $subscriptionId = $this->subscriptions->create($this->source, [
            'name' => 'Streaming',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        $this->attachments->upload(
            $this->source,
            $this->sourceUser,
            $subscriptionId,
            FakeUpload::pdf('invoice.pdf'),
        );

        $archive = $this->export();

        // Swap the stored PDF for something that is not one, keeping the entry
        // name. An archive is untrusted input however it was produced.
        $zip = new ZipArchive();
        $zip->open($archive);
        $entry = null;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (str_starts_with($name, 'files/attachments/')) {
                $entry = $name;
            }
        }
        self::assertNotNull($entry);
        $zip->addFromString($entry, "<?php echo 'not a pdf';");
        $zip->close();

        $summary = $this->backups->restore($this->target, $this->targetUser, $archive);

        // The subscription comes back; the file does not.
        self::assertSame(1, $summary['subscriptions']);
        self::assertSame(0, $summary['attachments']);
        self::assertSame(1, $summary['skipped']);
    }

    public function testTheTagVocabularyComesBackIncludingUnusedTags(): void
    {
        $this->subscriptions->create($this->source, [
            'name' => 'Tagged',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'tags' => 'applied',
        ]);

        // A label made but not yet used. It has no subscription to travel with,
        // so only the tag list carries it.
        (new \App\Repository\TagRepository($this->db))->resolveOrCreate($this->source, ['unused']);

        $summary = $this->backups->restore($this->target, $this->targetUser, $this->export());

        self::assertSame(2, $summary['tags']);

        $names = array_map(
            static fn ($tag): string => $tag->name,
            $this->container->get(\App\Service\TagService::class)->all($this->target),
        );

        self::assertEqualsCanonicalizing(['applied', 'unused'], $names);
    }

    public function testRestoreMatchesNamesWholeIgnoringCaseAndAccents(): void
    {
        $categories = new \App\Repository\CategoryRepository($this->db);
        $tags = new \App\Repository\TagRepository($this->db);

        $categories->create($this->source, 'Music', null);
        $categories->create($this->source, 'Music Videos', null);
        $categories->create($this->source, 'Café', null);
        $tags->create($this->source, 'TV');
        $tags->create($this->source, 'Apple TV');

        // The target already has near-names: a case variant of one, an accent
        // variant of another, and a name that merely contains one restored.
        $categories->create($this->target, 'MUSIC', null);
        $categories->create($this->target, 'Cafe', null);
        $tags->create($this->target, 'tv');

        $this->backups->restore($this->target, $this->targetUser, $this->export());

        $categoryNames = array_map(static fn ($c): string => $c->name, $categories->findAll($this->target));
        self::assertEqualsCanonicalizing(['MUSIC', 'Cafe', 'Music Videos'], $categoryNames);

        $tagNames = array_map(static fn ($t): string => $t->name, $tags->findAll($this->target));
        self::assertEqualsCanonicalizing(['tv', 'Apple TV'], $tagNames);
    }

    public function testTheHouseholdNameIsExportedButNotImposedOnRestore(): void
    {
        // Deliberate: a restore lands in a household that already exists and
        // already has a name its members chose.
        $this->subscriptions->create($this->source, [
            'name' => 'Anything',
            'price' => '1.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        $archive = $this->export();

        self::assertSame('Source household', $this->readArchiveJson($archive, 'data.json')['household']['name']);

        $this->backups->restore($this->target, $this->targetUser, $archive);

        $households = new HouseholdRepository($this->db);
        self::assertSame('Target household', $households->findById((int) $this->target->householdId)?->name);
    }

    public function testRestoringIsAdditiveAndNeverDeletes(): void
    {
        $this->subscriptions->create($this->target, [
            'name' => 'Already here',
            'price' => '1.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        $this->subscriptions->create($this->source, [
            'name' => 'From the archive',
            'price' => '2.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        $this->backups->restore($this->target, $this->targetUser, $this->export());

        $names = array_map(
            static fn ($subscription): string => $subscription->name,
            $this->subscriptions->allForStats($this->target, activeOnly: false),
        );

        self::assertEqualsCanonicalizing(['Already here', 'From the archive'], $names);
    }

    public function testPaymentMethodsAndTheirAssignmentsSurviveTheRoundTrip(): void
    {
        $methods = $this->container->get(PaymentMethodService::class);

        // The source has a method of its own, with a colour and an uploaded
        // logo, and one that shares its name with a default.
        $jointCard = $methods->create($this->source, 'Joint card', '#1069bb', FakeUpload::png('joint.png'));
        $paypal = $methods->create($this->source, 'PayPal', null);

        $this->subscriptions->create($this->source, [
            'name' => 'Streaming',
            'price' => '10.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'payment_method_id' => (string) $jointCard,
        ]);
        $this->subscriptions->create($this->source, [
            'name' => 'Music',
            'price' => '5.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'payment_method_id' => (string) $paypal,
        ]);
        $this->subscriptions->create($this->source, [
            'name' => 'Unassigned',
            'price' => '1.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        // The target already has the defaults, as every new household does.
        $methods->seedDefaults($this->target, 'en');
        $targetPaypal = null;
        foreach ($methods->all($this->target) as $method) {
            if ($method->name === 'PayPal') {
                $targetPaypal = $method->id;
            }
        }
        self::assertNotNull($targetPaypal);

        $archive = $this->export();
        $summary = $this->backups->restore($this->target, $this->targetUser, $archive);

        // "PayPal" merged into the one already there; only the new one counted.
        self::assertSame(1, $summary['payment_methods']);
        self::assertSame(3, $summary['subscriptions']);
        self::assertSame(0, $summary['skipped']);

        $restoredMethods = [];
        foreach ($methods->all($this->target) as $method) {
            $restoredMethods[$method->name] = $method;
        }
        self::assertCount(11, $restoredMethods);

        $joint = $restoredMethods['Joint card'];
        self::assertSame('#1069bb', $joint->colour);
        self::assertNotNull($joint->logoPath);
        self::assertMatchesRegularExpression('#^assets/logos/[0-9a-f]{32}\.png$#', (string) $joint->logoPath);
        self::assertFileExists($this->logoDirectory . '/' . basename((string) $joint->logoPath));

        $restored = [];
        foreach ($this->subscriptions->allForStats($this->target, activeOnly: false) as $subscription) {
            $restored[$subscription->name] = $subscription;
        }

        // By name, into the target's own rows — never the source's ids.
        self::assertSame($joint->id, $restored['Streaming']->paymentMethodId);
        self::assertSame($targetPaypal, $restored['Music']->paymentMethodId);
        self::assertNull($restored['Unassigned']->paymentMethodId);
        self::assertNotSame($jointCard, $restored['Streaming']->paymentMethodId);
    }

    public function testAnArchiveFromBeforePaymentMethodsStillRestores(): void
    {
        $this->subscriptions->create($this->source, [
            'name' => 'Streaming',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        $archive = $this->export();

        // Rewrite the archive the way an older version wrote it: no list of
        // payment methods and no assignment on the rows.
        $data = $this->readArchiveJson($archive, 'data.json');
        unset($data['payment_methods']);
        foreach ($data['subscriptions'] as $index => $row) {
            unset(
                $data['subscriptions'][$index]['payment_method_id'],
                $data['subscriptions'][$index]['payment_method_name'],
            );
        }
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        $zip->addFromString('data.json', json_encode($data, JSON_THROW_ON_ERROR));
        $zip->close();

        $summary = $this->backups->restore($this->target, $this->targetUser, $archive);

        self::assertSame(1, $summary['subscriptions']);
        self::assertSame(0, $summary['payment_methods']);
        self::assertNull($this->subscriptions->allForStats($this->target, activeOnly: false)[0]->paymentMethodId);
    }

    public function testAnIsolatedExportCarriesTheMethodsButOnlyTheExportersRows(): void
    {
        $methods = $this->container->get(PaymentMethodService::class);
        $card = $methods->create($this->source, 'Owner card', null);

        // The owner's row, paid with the owner's card.
        $this->subscriptions->create($this->source, [
            'name' => 'Owner only',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'payment_method_id' => (string) $card,
        ]);

        $partner = Scope::forMember(
            $this->partnerId,
            false,
            (int) $this->source->householdId,
            Role::Editor,
            IsolationMode::Isolated,
        );
        $partnerUser = (new UserRepository($this->db))->findById($this->partnerId);
        self::assertNotNull($partnerUser);

        $path = $this->backups->export($partner, $partnerUser, 'Test instance');
        $this->temporaryFiles[] = $path;
        $data = $this->readArchiveJson($path, 'data.json');

        // Household-wide labels travel, as categories do; somebody else's
        // subscription does not, whatever it is paid with.
        self::assertSame(['Owner card'], array_column($data['payment_methods'], 'name'));
        self::assertSame([], $data['subscriptions']);
    }

    public function testPlanVisibilityAndCancellationSurviveTheRoundTrip(): void
    {
        $base = [
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ];
        $this->subscriptions->create($this->source, ['name' => 'Streaming', 'plan' => 'Family'] + $base);
        $this->subscriptions->create($this->source, ['name' => 'Mine', 'visibility' => 'payer'] + $base);
        $gone = $this->subscriptions->create($this->source, ['name' => 'Gone'] + $base);
        $this->subscriptions->cancel($this->source, $gone);

        $summary = $this->backups->restore($this->target, $this->targetUser, $this->export());
        self::assertSame(3, $summary['subscriptions']);
        self::assertSame(0, $summary['skipped']);

        $restored = [];
        foreach ($this->subscriptions->allForStats($this->target, activeOnly: false) as $subscription) {
            $restored[$subscription->name] = $subscription;
        }

        self::assertSame('Family', $restored['Streaming']->plan);
        // The exporter's own private row, restored by somebody not in the
        // target household's member list for it: it lands with the restorer
        // and stays private.
        self::assertSame(\App\Domain\Visibility::Payer, $restored['Mine']->visibility);
        self::assertSame('2026-06-01', $restored['Gone']->cancelledAt?->format('Y-m-d'));
        self::assertFalse($restored['Gone']->isActive);
    }

    public function testTheTermsSurviveTheRoundTrip(): void
    {
        $base = [
            'price' => '58.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ];
        $this->subscriptions->create($this->source, [
            'name' => 'Energy',
            'contract_ends_on' => '2027-03-14',
            'payments_end_on' => '2028-11-01',
            'amount_varies' => '1',
        ] + $base);
        $this->subscriptions->create($this->source, ['name' => 'Plain'] + $base);

        $this->backups->restore($this->target, $this->targetUser, $this->export());

        $restored = [];
        foreach ($this->subscriptions->allForStats($this->target, activeOnly: false) as $subscription) {
            $restored[$subscription->name] = $subscription;
        }

        self::assertSame('2027-03-14', $restored['Energy']->contractEndsOn?->format('Y-m-d'));
        self::assertSame('2028-11-01', $restored['Energy']->paymentsEndOn?->format('Y-m-d'));
        self::assertTrue($restored['Energy']->amountVaries);
        self::assertNull($restored['Plain']->contractEndsOn);
        self::assertNull($restored['Plain']->paymentsEndOn);
        self::assertFalse($restored['Plain']->amountVaries);
    }

    public function testAnIntroOfferSurvivesTheRoundTrip(): void
    {
        $base = [
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-06-15',
            'is_promotional' => '1',
        ];
        $this->subscriptions->create($this->source, [
            'name' => 'Spotify',
            'price' => '5.99',
            'offer_ends_on' => '2026-09-01',
            'offer_then_price' => '11.99',
        ] + $base);
        $this->subscriptions->create($this->source, ['name' => 'Open-ended', 'price' => '3.00'] + $base);
        $this->subscriptions->create($this->source, [
            'name' => 'Ordinary',
            'price' => '9.99',
            'is_promotional' => '0',
        ] + $base);

        $archive = $this->export();
        $rows = [];
        foreach ($this->readArchiveJson($archive, 'data.json')['subscriptions'] as $row) {
            $rows[$row['name']] = $row;
        }
        self::assertTrue($rows['Spotify']['price_is_promotional']);
        self::assertSame('2026-09-01', $rows['Spotify']['promo_ends_on']);
        self::assertSame(1199, $rows['Spotify']['promo_then_price_minor']);
        self::assertTrue($rows['Open-ended']['price_is_promotional']);
        self::assertNull($rows['Open-ended']['promo_ends_on']);
        self::assertFalse($rows['Ordinary']['price_is_promotional']);

        $summary = $this->backups->restore($this->target, $this->targetUser, $archive);
        self::assertSame(3, $summary['subscriptions']);
        self::assertSame(0, $summary['skipped']);

        $prices = $this->container->get(PriceHistoryService::class);
        $restored = [];
        foreach ($this->subscriptions->allForStats($this->target, activeOnly: false) as $subscription) {
            $restored[$subscription->name] = $prices->promotionFor($this->target, $subscription->id);
        }

        self::assertSame('2026-09-01', $restored['Spotify']['ends_on']?->format('Y-m-d'));
        self::assertSame(1199, $restored['Spotify']['then']?->amountMinor);
        self::assertNotNull($restored['Open-ended']);
        self::assertNull($restored['Open-ended']['ends_on']);
        self::assertNull($restored['Ordinary']);
    }

    public function testABackupFromBeforeIntroOffersRestoresAnOrdinaryPrice(): void
    {
        $this->subscriptions->create($this->source, [
            'name' => 'Spotify',
            'price' => '5.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-06-15',
            'is_promotional' => '1',
        ]);

        $archive = $this->export();
        $data = $this->readArchiveJson($archive, 'data.json');
        foreach ($data['subscriptions'] as $index => $row) {
            unset(
                $data['subscriptions'][$index]['price_is_promotional'],
                $data['subscriptions'][$index]['promo_ends_on'],
                $data['subscriptions'][$index]['promo_then_price_minor'],
            );
        }
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        $zip->addFromString('data.json', json_encode($data, JSON_THROW_ON_ERROR));
        $zip->close();

        $summary = $this->backups->restore($this->target, $this->targetUser, $archive);
        self::assertSame(1, $summary['subscriptions']);

        $restored = $this->subscriptions->allForStats($this->target, activeOnly: false)[0];
        $history = $this->container->get(PriceHistoryService::class)->historyFor($this->target, $restored->id);
        self::assertCount(1, $history);
        self::assertFalse($history[0]->isPromotional);
    }

    public function testAnotherMembersPrivateSubscriptionIsLeftOutAndCounted(): void
    {
        $partner = $this->partnerScope();
        $this->subscriptions->create($partner, [
            'name' => 'Partner private',
            'price' => '20.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'visibility' => 'payer',
        ]);

        self::assertSame(1, $this->backups->privateLeftOut($this->source));

        $archive = $this->export();
        self::assertSame([], $this->readArchiveJson($archive, 'data.json')['subscriptions']);
        self::assertSame(1, $this->readArchiveJson($archive, 'manifest.json')['private_left_out']);
    }

    public function testAPrivateRowRestoredForAnotherMemberStaysTheirs(): void
    {
        // The partner exports their own household view, private row included.
        $partner = $this->partnerScope();
        $this->subscriptions->create($partner, [
            'name' => 'Partner private',
            'price' => '20.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'visibility' => 'payer',
        ]);
        $users = new UserRepository($this->db);
        $archive = $this->backups->export($partner, $users->findById($this->partnerId), 'Test instance');
        $this->temporaryFiles[] = $archive;

        // The target's owner restores it. The partner is a member there, so
        // the row goes back to them — and so out of the restorer's sight.
        $summary = $this->backups->restore($this->target, $this->targetUser, $archive);
        self::assertSame(1, $summary['subscriptions']);

        self::assertSame([], $this->subscriptions->allForStats($this->target, activeOnly: false));
        self::assertSame(1, $this->subscriptions->countPrivateToOthers($this->target));
    }

    public function testBudgetSubjectsSurviveTheRoundTrip(): void
    {
        $base = ['period' => 'monthly', 'amount' => '50.00', 'currency' => 'GBP'];
        $this->budgets->create($this->source, [
            'name' => 'Partner',
            'subject_user_id' => (string) $this->partnerId,
        ] + $base);
        $this->budgets->create($this->source, [
            'name' => 'Everyone',
            'subject_user_id' => BudgetService::SUBJECT_HOUSEHOLD,
        ] + $base);
        $this->budgets->create($this->source, ['name' => 'Mine'] + $base);

        $this->backups->restore($this->target, $this->targetUser, $this->export());

        $restored = [];
        foreach ($this->budgets->all($this->target, activeOnly: false) as $budget) {
            $restored[$budget->name] = $budget;
        }

        self::assertSame($this->partnerId, $restored['Partner']->subjectUserId);
        self::assertTrue($restored['Everyone']->isHousehold());
        // The source owner is not in the target household, so their budget —
        // like their rows — comes back as the restorer's own.
        self::assertSame($this->targetUser->id, $restored['Mine']->subjectUserId);
    }

    public function testTheIntervalAndTheLastDayAnchorSurviveTheRoundTrip(): void
    {
        $this->subscriptions->create($this->source, [
            'name' => 'Car insurance',
            'price' => '180.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'cycle_interval' => '6',
            'next_payment_date' => '2026-06-30',
            'anchor_last_day' => '1',
        ]);

        $this->backups->restore($this->target, $this->targetUser, $this->export());

        $restored = $this->subscriptions->allForStats($this->target, activeOnly: false)[0];
        self::assertSame(6, $restored->cycleInterval);
        self::assertSame(31, $restored->anchorDay);
    }

    public function testABackupFromBeforeIntervalsRestoresWithOne(): void
    {
        $this->subscriptions->create($this->source, [
            'name' => 'Old archive',
            'price' => '10.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'cycle_interval' => '6',
            'next_payment_date' => '2026-07-01',
        ]);

        // Take the column out, as an archive written before Phase 32 has it.
        $archive = $this->export();
        $data = $this->readArchiveJson($archive, 'data.json');
        self::assertArrayHasKey('cycle_interval', $data['subscriptions'][0]);
        unset($data['subscriptions'][0]['cycle_interval'], $data['subscriptions'][0]['converts_to_cycle_interval']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        $zip->addFromString('data.json', json_encode($data, JSON_THROW_ON_ERROR));
        $zip->close();

        $summary = $this->backups->restore($this->target, $this->targetUser, $archive);
        self::assertSame(1, $summary['subscriptions']);

        $restored = $this->subscriptions->allForStats($this->target, activeOnly: false)[0];
        self::assertSame(1, $restored->cycleInterval);
    }

    public function testThePaymentLedgerAndItsInvoicesSurviveTheRoundTrip(): void
    {
        $id = $this->subscriptions->create($this->source, [
            'name' => 'Energy',
            'price' => '58.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-06-15',
            'start_date' => '2026-01-15',
        ]);
        $charges = $this->container->get(ChargeService::class);
        $this->container->get(LedgerBackfillRunner::class)->run();
        $rows = $charges->forSubscription($this->source, $id);
        self::assertCount(5, $rows);
        $charges->recordActual($this->source, $rows[0]->id, ['actual' => '63.20', 'note' => 'Winter']);
        $charges->skip($this->source, $rows[1]->id);
        $pdf = FakeUpload::pdf('May.pdf');
        $this->attachments->upload($this->source, $this->sourceUser, $id, $pdf, null, $rows[0]->id);

        $summary = $this->backups->restore($this->target, $this->targetUser, $this->export());
        self::assertSame(5, $summary['charges']);

        $restored = $this->subscriptions->allForStats($this->target, activeOnly: false)[0];
        $copies = $charges->forSubscription($this->target, $restored->id);
        self::assertSame(
            [['2026-05-15', 6320, 'Winter', 'charged'], ['2026-04-15', 0, null, 'skipped']],
            array_map(
                static fn ($charge): array => [
                    $charge->dueDate->format('Y-m-d'),
                    $charge->amount()->amountMinor,
                    $charge->note,
                    $charge->status->value,
                ],
                array_slice($copies, 0, 2),
            ),
        );
        self::assertSame('reconstructed', $copies[4]->source->value);

        $invoice = $this->attachments->forSubscription($this->target, $restored->id)[0];
        self::assertSame($copies[0]->id, $invoice->chargeId);
    }

    public function testABackupFromBeforeTheLedgerRestoresWithItEmpty(): void
    {
        $this->subscriptions->create($this->source, [
            'name' => 'Energy',
            'price' => '58.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-06-15',
            'start_date' => '2026-01-15',
        ]);
        $this->container->get(LedgerBackfillRunner::class)->run();

        $archive = $this->export();
        $data = $this->readArchiveJson($archive, 'data.json');
        unset($data['charges']);
        foreach ($data['attachments'] as $index => $row) {
            unset($data['attachments'][$index]['charge_index']);
        }
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        $zip->addFromString('data.json', json_encode($data, JSON_THROW_ON_ERROR));
        $zip->close();

        $summary = $this->backups->restore($this->target, $this->targetUser, $archive);

        self::assertSame(1, $summary['subscriptions']);
        self::assertSame(0, $summary['charges']);
        self::assertSame([], $this->container->get(ChargeService::class)->all($this->target));
    }

    public function testADiscountMarkedOnAPaymentSurvivesABackupAndRestore(): void
    {
        $this->subscriptions->create($this->source, [
            'name' => 'Energy',
            'price' => '58.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-06-15',
            'start_date' => '2026-04-15',
        ]);
        $this->container->get(LedgerBackfillRunner::class)->run();
        $charges = $this->container->get(ChargeService::class);
        $charge = $charges->all($this->source)[0];
        $charges->recordActual($this->source, $charge->id, ['actual' => '50.00', 'discount' => '1']);

        $this->backups->restore($this->target, $this->targetUser, $this->export());

        $restored = $charges->all($this->target)[0];
        self::assertSame([5000, 5800, true, 800], [
            $restored->actualMinor,
            $restored->referenceMinor,
            $restored->isDiscount(),
            $restored->saving()->amountMinor,
        ]);
    }

    public function testASubscriptionRowOfTheWrongTypeIsSkippedNotTheWholeRestore(): void
    {
        foreach (['Energy', 'Water'] as $name) {
            $this->subscriptions->create($this->source, [
                'name' => $name,
                'price' => '10.00',
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => '2026-06-15',
            ]);
        }

        $archive = $this->export();
        $data = $this->readArchiveJson($archive, 'data.json');
        $data['subscriptions'][0]['notes'] = [];
        $data['subscriptions'][0]['payments_end_on'] = true;
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        $zip->addFromString('data.json', json_encode($data, JSON_THROW_ON_ERROR));
        $zip->close();

        $summary = $this->backups->restore($this->target, $this->targetUser, $archive);

        self::assertSame(1, $summary['subscriptions']);
        self::assertSame(1, $summary['skipped']);
    }

    public function testABadPaymentRowInAnArchiveIsSkippedNotWritten(): void
    {
        $this->subscriptions->create($this->source, [
            'name' => 'Energy',
            'price' => '58.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-06-15',
            'start_date' => '2026-04-15',
        ]);
        $this->container->get(LedgerBackfillRunner::class)->run();

        $archive = $this->export();
        $data = $this->readArchiveJson($archive, 'data.json');
        // A reference travels with its charge (Phase 36).
        $good = ['reference_minor' => 6000] + $data['charges'][0];
        $data['charges'] = [
            $good,
            'not a row',
            ['subscription_index' => 99] + $good,
            ['expected_minor' => -1, 'due_date' => '2026-01-01'] + $good,
            ['actual_minor' => -5, 'due_date' => '2026-01-02'] + $good,
            ['reference_minor' => -5, 'due_date' => '2026-01-06'] + $good,
            ['status' => 'refunded', 'due_date' => '2026-01-03'] + $good,
            ['source' => 'bank', 'due_date' => '2026-01-04'] + $good,
            ['currency' => 'XX', 'due_date' => '2026-01-05'] + $good,
            ['due_date' => 'soon'] + $good,
            $good,
        ];
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        $zip->addFromString('data.json', json_encode($data, JSON_THROW_ON_ERROR));
        $zip->close();

        $summary = $this->backups->restore($this->target, $this->targetUser, $archive);

        self::assertSame(1, $summary['charges']);
        self::assertSame(10, $summary['skipped']);
        $restored = $this->container->get(ChargeService::class)->all($this->target);
        self::assertCount(1, $restored);
        self::assertSame(6000, $restored[0]->referenceMinor);
    }

    public function testARestoreThatFailsPartWayKeepsNothing(): void
    {
        $id = $this->subscriptions->create($this->source, [
            'name' => 'Broadband',
            'price' => '35.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);
        $this->attachments->upload($this->source, $this->sourceUser, $id, FakeUpload::pdf('Bill.pdf'));
        $archive = $this->export();

        // The target household's folder exists and cannot be written: the
        // attachment fails to store after its subscription was created (K16).
        $folder = $this->attachmentDirectory . '/' . $this->target->householdId;
        mkdir($folder, 0o750, true);
        chmod($folder, 0o500);

        try {
            $this->backups->restore($this->target, $this->targetUser, $archive);
            self::fail('The restore succeeded into an unwritable folder.');
        } catch (\RuntimeException) {
            // Expected.
        } finally {
            chmod($folder, 0o750);
        }

        self::assertSame([], $this->subscriptions->allForStats($this->target, activeOnly: false));
    }

    public function testAnOverlongAttachmentFilenameIsShortenedRatherThanFailingTheRestore(): void
    {
        $id = $this->subscriptions->create($this->source, [
            'name' => 'Broadband',
            'price' => '35.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);
        $this->attachments->upload($this->source, $this->sourceUser, $id, FakeUpload::pdf('Bill.pdf'));

        $archive = $this->export();
        $data = $this->readArchiveJson($archive, 'data.json');
        $data['attachments'][0]['filename'] = str_repeat('a', 300) . '.pdf';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        $zip->addFromString('data.json', json_encode($data, JSON_THROW_ON_ERROR));
        $zip->close();

        $summary = $this->backups->restore($this->target, $this->targetUser, $archive);

        self::assertSame(1, $summary['attachments']);
        $restored = $this->subscriptions->allForStats($this->target, activeOnly: false)[0];
        $copy = $this->attachments->forSubscription($this->target, $restored->id)[0];
        self::assertSame(255, mb_strlen($copy->originalFilename));
    }

    private function partnerScope(): Scope
    {
        return Scope::forMember(
            $this->partnerId,
            false,
            (int) $this->source->householdId,
            Role::Editor,
            IsolationMode::Shared,
        );
    }

    private function export(): string
    {
        $path = $this->backups->export($this->source, $this->sourceUser, 'Test instance');
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readArchiveJson(string $archive, string $entry): array
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        $contents = $zip->getFromName($entry);
        $zip->close();

        self::assertIsString($contents);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function householdWithoutThePartner(): Scope
    {
        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $id = $users->create('solo@example.test', 'Solo', 'hash', false, $this->clock->now());
        $householdId = $households->create('Solo household', $id);
        $memberships->create($householdId, $id, Role::OwnerAdmin);

        return Scope::forMember($id, false, $householdId, Role::OwnerAdmin, IsolationMode::Shared);
    }

    private function temporary(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'renovo-test-zip');
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function buildContainer(): ContainerInterface
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $settings['database'] = [
            'driver' => $this->env('DB_DRIVER', 'pgsql'),
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ];
        $settings['uploads']['attachment_directory'] = $this->attachmentDirectory;
        $settings['uploads']['logo_directory'] = $this->logoDirectory;

        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/config/container.php')($builder, $settings);

        $builder->addDefinitions([
            Clock::class => $this->clock,
            AttachmentStorage::class => factory(
                fn (): AttachmentStorage => new AttachmentStorage($this->attachmentDirectory, 1024 * 1024),
            ),
            BackupService::class => autowire(BackupService::class)
                ->constructorParameter('logoDirectory', factory(fn (): string => $this->logoDirectory)),
        ]);

        return $builder->build();
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
