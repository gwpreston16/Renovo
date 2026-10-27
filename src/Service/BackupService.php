<?php

declare(strict_types=1);

namespace App\Service;

use App\Persistence\Database;
use App\Repository\ChargeRepository;
use App\Domain\ChargeStatus;
use App\Domain\ChargeSource;
use App\Application\Api\Resource;
use App\Application\Api\SubscriptionPayload;
use App\Domain\AuditAction;
use App\Domain\BillingCycle;
use App\Domain\Currency;
use App\Domain\Money;
use App\Domain\NameKey;
use App\Domain\TagLimits;
use App\Domain\Entity\Attachment;
use App\Domain\Entity\Budget;
use App\Domain\Entity\Category;
use App\Domain\Entity\PaymentMethod;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\Tag;
use App\Domain\Entity\User;
use App\Repository\AttachmentRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\TagRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Exporting a household and putting it back.
 *
 * A backup here is not a database dump, and the difference is the whole point.
 * It is built by reading through the scoping layer, so what lands in the archive
 * is exactly what the person asking could already see — in ISOLATED mode a
 * member exports their own subscriptions and nobody else's — and it is restored
 * by calling the same services the forms call, so a restored row is validated,
 * gets its price-history entry and obeys the isolation rules of the instance it
 * is landing in, which may not be the one it left.
 *
 * What is deliberately *not* in it: instance settings, user accounts, passwords,
 * API tokens, the audit log. Those belong to the instance rather than to the
 * household, and a household Owner who could export and re-import them would be
 * able to reconfigure the server through the backup screen. Members are
 * referenced by email address only, which is enough to put a subscription back
 * with the right owner and not enough to create an account.
 *
 * One asymmetry is deliberate: the household's *name* is written into the
 * archive and is not read back. A restore lands in a household that already
 * exists and already has a name its members chose; silently renaming it to
 * whatever the archive said would be a surprising side effect of importing some
 * subscriptions. It is exported so that a person opening the file can tell
 * whose data it is.
 *
 * The archive is treated as hostile on the way back in, whatever it claims
 * about where it came from: entry names are checked against a strict pattern
 * before anything is extracted, and every file in it is re-validated by its
 * magic bytes exactly as an upload would be.
 *
 * @phpstan-type RestoreSummary array{subscriptions: int, categories: int, payment_methods: int, tags: int,
 *     budgets: int, attachments: int, charges: int, skipped: int, tags_trimmed: int}
 */
final class BackupService
{
    public const FORMAT = 'renovo-household-backup';
    public const FORMAT_VERSION = 1;

    /**
     * What an entry inside the archive may be called. Anything else — an
     * absolute path, a `..`, a name with a separator in it — is refused
     * outright rather than sanitised, because a name that needed sanitising was
     * not produced by this application.
     */
    private const ENTRY_PATTERN = '#^(manifest\.json|data\.json|files/(logos|attachments)/[A-Za-z0-9._-]{1,128})$#';

    private const MAX_ENTRIES = 20000;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly CategoryService $categories,
        private readonly PaymentMethodService $paymentMethods,
        private readonly TagService $tags,
        private readonly TagRepository $tagRepository,
        private readonly BudgetService $budgets,
        private readonly AttachmentRepository $attachments,
        private readonly AttachmentStorage $attachmentStorage,
        private readonly LogoStorage $logos,
        private readonly HouseholdRepository $households,
        private readonly MembershipRepository $memberships,
        private readonly AuditLogService $audit,
        private readonly PriceHistoryService $priceHistory,
        private readonly ChargeRepository $charges,
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly string $logoDirectory,
    ) {
    }

    /**
     * Write a backup archive and return the path to it.
     *
     * The caller is responsible for streaming and then deleting it; it is
     * written to a temporary file rather than built in memory because a
     * household with a few hundred invoices in it would not fit comfortably.
     */
    public function export(Scope $scope, User $actor, string $instanceName): string
    {
        $path = tempnam(sys_get_temp_dir(), 'renovo-backup');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the backup.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup archive.');
        }

        $today = $this->clock->today();
        $emails = $this->memberEmails($scope);

        $subscriptions = [];
        $logos = [];
        // Attachments refer to their subscription by its position in this list,
        // not by its id. Ids are not preserved across a restore — the rows are
        // created fresh — so a positional reference is the only one that still
        // means something on the way back in.
        $positions = [];
        $promotions = $this->priceHistory->promotions($scope);
        foreach ($this->subscriptions->allForStats($scope, activeOnly: false) as $subscription) {
            $positions[$subscription->id] = count($subscriptions);
            $subscriptions[] = $this->exportSubscription(
                $subscription,
                $today,
                $emails,
                $logos,
                $promotions[$subscription->id] ?? null,
            );
        }

        // The payment ledger, by the same positional reference, and each
        // charge's own position so an invoice can say which one it is for.
        $charges = [];
        $chargePositions = [];
        foreach ($this->charges->findAllInScope($scope) as $charge) {
            $position = $positions[$charge->subscriptionId] ?? null;
            if ($position === null) {
                continue;
            }

            $chargePositions[$charge->id] = count($charges);
            $charges[] = [
                'subscription_index' => $position,
                'due_date' => $charge->dueDate->format('Y-m-d'),
                'currency' => $charge->expected->currency,
                'expected_minor' => $charge->expected->amountMinor,
                'actual_minor' => $charge->actualMinor,
                'reference_minor' => $charge->referenceMinor,
                'status' => $charge->status->value,
                'source' => $charge->source->value,
                'note' => $charge->note,
            ];
        }

        $attachments = [];
        foreach ($this->attachments->findAllForHousehold($scope) as $attachment) {
            $position = $positions[$attachment->subscriptionId] ?? null;
            if ($position === null) {
                // Its subscription was not exported, so neither is it.
                continue;
            }

            $entry = $this->addAttachment($zip, $attachment, $position);
            if ($entry !== null) {
                $entry['charge_index'] = $attachment->chargeId === null
                    ? null
                    : ($chargePositions[$attachment->chargeId] ?? null);
                $attachments[] = $entry;
            }
        }

        // Payment methods before the logos are written, because a method's
        // logo joins the same list: it is filed in the same directory by the
        // same storage, and goes into the archive the same way.
        // A loop rather than `array_map`: an arrow function captures `$logos`
        // by value, and the entries it added would never reach the archive.
        $paymentMethods = [];
        foreach ($this->paymentMethods->all($scope) as $method) {
            $paymentMethods[] = $this->exportPaymentMethod($method, $logos);
        }

        foreach ($logos as $storedPath => $entryName) {
            $absolute = $this->logoAbsolutePath($storedPath);
            if ($absolute !== null) {
                $zip->addFile($absolute, $entryName);
            }
        }

        $data = [
            'household' => [
                'name' => $scope->householdId === null
                    ? ''
                    : ($this->households->findById($scope->householdId)->name ?? ''),
            ],
            'categories' => array_map(
                static fn (Category $category): array => ['name' => $category->name, 'colour' => $category->colour],
                $this->categories->all($scope),
            ),
            'payment_methods' => $paymentMethods,
            'tags' => array_map(
                static fn (Tag $tag): array => ['name' => $tag->name],
                $this->tags->all($scope),
            ),
            'subscriptions' => $subscriptions,
            'budgets' => array_map(
                fn (Budget $budget): array => $this->exportBudget($budget, $emails),
                $this->budgets->all($scope, activeOnly: false),
            ),
            'attachments' => $attachments,
            'charges' => $charges,
        ];

        $zip->addFromString('manifest.json', $this->encode([
            'format' => self::FORMAT,
            'version' => self::FORMAT_VERSION,
            'generated_at' => $this->clock->now()->format(DATE_ATOM),
            'instance' => $instanceName,
            'isolation_mode' => $scope->isolationMode->value,
            // Other members' "only me" subscriptions are not in the archive:
            // it holds what the exporter could see, and a private row is one
            // they cannot. Said here so the file itself admits the gap.
            'private_left_out' => $this->subscriptions->countPrivateToOthers($scope),
            'counts' => [
                'subscriptions' => count($data['subscriptions']),
                'categories' => count($data['categories']),
                'payment_methods' => count($data['payment_methods']),
                'tags' => count($data['tags']),
                'budgets' => count($data['budgets']),
                'attachments' => count($data['attachments']),
                'charges' => count($data['charges']),
            ],
        ]));

        $zip->addFromString('data.json', $this->encode($data));
        $zip->close();

        $this->audit->record(AuditAction::BackupExported, $actor, [
            'subscriptions' => count($data['subscriptions']),
            'attachments' => count($data['attachments']),
        ], $scope->householdId);

        return $path;
    }

    /**
     * How many of the household's subscriptions an export by this scope will
     * leave out for being private to somebody else.
     */
    public function privateLeftOut(Scope $scope): int
    {
        return $this->subscriptions->countPrivateToOthers($scope);
    }

    public function suggestedFilename(string $instanceName): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $instanceName));
        $slug = trim($slug, '-');

        return sprintf('%s-backup-%s.zip', $slug === '' ? 'renovo' : $slug, $this->clock->today()->format('Y-m-d'));
    }

    /**
     * Restore an archive into the current household.
     *
     * Additive, never destructive: it creates rows, it does not delete or
     * overwrite any. Restoring the same archive twice leaves two copies of
     * everything, which is an obvious and recoverable outcome. The alternative —
     * "replace everything" — is one mis-click away from destroying a household's
     * data with a stale file, and nothing in this phase asked for it.
     *
     * @return RestoreSummary
     * @throws ValidationException
     */
    public function restore(Scope $scope, User $actor, string $archivePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw ValidationException::field('file', 'error.backup.not_zip');
        }

        $written = [];

        try {
            $this->assertSafeEntries($zip);

            $data = $this->readJson($zip, 'data.json');
            $manifest = $this->readJson($zip, 'manifest.json');

            if (($manifest['format'] ?? null) !== self::FORMAT) {
                throw ValidationException::field('file', 'error.backup.foreign');
            }

            // One transaction, so a restore is all or nothing (K16). A row that
            // fails validation is skipped and counted inside it; anything else —
            // a full disk, a file the engine refuses — rolls every row back,
            // and the files this run wrote are removed below with them. Keeping
            // the rows while removing their files left subscriptions pointing
            // at logos and invoices that were no longer there.
            $summary = $this->db->transactional(
                fn (): array => $this->restoreData($scope, $zip, $data, $written),
            );
        } catch (Throwable $exception) {
            $this->removeWritten($written);
            $zip->close();

            throw $exception;
        }

        $zip->close();

        $this->audit->record(AuditAction::BackupRestored, $actor, $summary, $scope->householdId);

        return $summary;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{kind: string, path: string}> $written
     * @return RestoreSummary
     */
    private function restoreData(Scope $scope, ZipArchive $zip, array $data, array &$written): array
    {
        $summary = [
            'subscriptions' => 0,
            'categories' => 0,
            'payment_methods' => 0,
            'tags' => 0,
            'budgets' => 0,
            'attachments' => 0,
            'charges' => 0,
            'skipped' => 0,
            'tags_trimmed' => 0,
        ];

        $categoryIds = $this->restoreCategories($scope, $data['categories'] ?? [], $summary);
        // Absent from an archive written before payment methods existed, which
        // restores exactly as it always did.
        $paymentMethodIds = $this->restorePaymentMethods(
            $scope,
            $zip,
            $data['payment_methods'] ?? [],
            $written,
            $summary,
        );
        $summary['tags'] = $this->restoreTags($scope, $data['tags'] ?? [], $summary);
        $owners = $this->memberIdsByEmail($scope);

        /** @var array<int, int> $subscriptionIds Archive index => new id. */
        $subscriptionIds = [];

        $rows = is_array($data['subscriptions'] ?? null) ? $data['subscriptions'] : [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $summary['skipped']++;
                continue;
            }

            try {
                $input = $this->subscriptionInput($scope, $row, $categoryIds, $paymentMethodIds, $owners);
            } catch (ValidationException) {
                // A field of the wrong JSON type: the row is skipped like any
                // other that fails validation, not the whole restore.
                $summary['skipped']++;
                continue;
            }

            $input['tags'] = $this->restorableTags($input['tags'] ?? [], $summary);

            $logo = $this->restoreLogo($zip, $row, $written);
            if ($logo !== null) {
                $input['logo_path'] = $logo;
            }

            try {
                $subscriptionIds[(int) $index] = $this->subscriptions->create($scope, $input, restoring: true);
                $summary['subscriptions']++;
            } catch (ValidationException) {
                $summary['skipped']++;
            }
        }

        $this->restoreBudgets($scope, $data['budgets'] ?? [], $categoryIds, $owners, $summary);
        // Absent from an archive written before the ledger existed, which
        // restores with the ledger empty.
        $chargeIds = $this->restoreCharges($scope, $data['charges'] ?? [], $subscriptionIds, $summary);
        $this->restoreAttachments(
            $scope,
            $zip,
            $data['attachments'] ?? [],
            $subscriptionIds,
            $chargeIds,
            $written,
            $summary,
        );

        return $summary;
    }

    /**
     * @param mixed $rows
     * @param RestoreSummary $summary
     * @return array<string, int> Lower-cased name => id.
     */
    private function restoreCategories(Scope $scope, mixed $rows, array &$summary): array
    {
        $ids = [];

        // Whatever the household already has keeps its id: restoring must not
        // create a second "Streaming" beside the existing one.
        foreach ($this->categories->all($scope) as $existing) {
            $ids[NameKey::of($existing->name)] = $existing->id;
        }

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null)) {
                continue;
            }

            $key = NameKey::of(trim($row['name']));
            if ($key === '' || isset($ids[$key])) {
                continue;
            }

            try {
                $colour = is_string($row['colour'] ?? null) ? $row['colour'] : null;
                $ids[$key] = $this->categories->create($scope, trim($row['name']), $colour);
                $summary['categories']++;
            } catch (ValidationException) {
                $summary['skipped']++;
            }
        }

        return $ids;
    }

    /**
     * Put the household's payment methods back, merged by name.
     *
     * The household being restored into almost certainly has payment methods
     * already — every new household is given the defaults — so merging is the
     * ordinary case rather than the edge one. A method that exists keeps its
     * id, its name and its colour; it gains the archive's logo only if it has
     * none of its own, so a restore never replaces a picture somebody chose.
     *
     * @param mixed $rows
     * @param list<array{kind: string, path: string}> $written
     * @param RestoreSummary $summary
     * @return array<string, int> Lower-cased name => id.
     */
    private function restorePaymentMethods(
        Scope $scope,
        ZipArchive $zip,
        mixed $rows,
        array &$written,
        array &$summary,
    ): array {
        $ids = [];
        $hasLogo = [];

        foreach ($this->paymentMethods->all($scope) as $existing) {
            $key = NameKey::of($existing->name);
            $ids[$key] = $existing->id;
            $hasLogo[$key] = $existing->logoPath !== null;
        }

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null)) {
                continue;
            }

            $key = NameKey::of(trim($row['name']));
            if ($key === '') {
                continue;
            }

            if (!isset($ids[$key])) {
                try {
                    $ids[$key] = $this->paymentMethods->create(
                        $scope,
                        trim($row['name']),
                        is_string($row['colour'] ?? null) ? $row['colour'] : null,
                        null,
                        is_string($row['icon'] ?? null) ? $row['icon'] : null,
                    );
                    $hasLogo[$key] = false;
                    $summary['payment_methods']++;
                } catch (ValidationException) {
                    $summary['skipped']++;
                    continue;
                }
            }

            if ($hasLogo[$key]) {
                continue;
            }

            $logo = $this->restoreLogo($zip, $row, $written);
            if ($logo !== null) {
                $this->paymentMethods->attachStoredLogo($scope, $ids[$key], $logo);
                $hasLogo[$key] = true;
            }
        }

        return $ids;
    }

    /**
     * Put the household's tag vocabulary back.
     *
     * Tags carried *on* a subscription are restored with it, so this covers the
     * ones that were not in use at the time — a label somebody made and has not
     * applied yet. `resolveOrCreate` is the same call the subscription form
     * makes, so an existing tag is matched rather than duplicated.
     *
     * @param mixed $rows
     * @param array<string, int> $summary
     */
    private function restoreTags(Scope $scope, mixed $rows, array &$summary): int
    {
        $names = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_string($row['name'] ?? null) && trim($row['name']) !== '') {
                $name = trim($row['name']);
                if (!TagLimits::nameFits($name)) {
                    // A tag no screen could have made: left out and counted,
                    // not allowed to fail the whole restore.
                    $summary['skipped']++;
                    continue;
                }
                $names[] = $name;
            }
        }

        if ($names === []) {
            return 0;
        }

        $before = count($this->tags->all($scope));
        $this->tagRepository->resolveOrCreate($scope, $names);

        return count($this->tags->all($scope)) - $before;
    }

    /**
     * A row's tags as the subscription form would hold them: a name over the
     * limit is dropped (and counted as skipped), and the first 25 are kept
     * with the row reported in `tags_trimmed` rather than the row lost.
     *
     * @param array<string, int> $summary
     * @return list<string>
     */
    private function restorableTags(mixed $tags, array &$summary): array
    {
        $names = [];
        foreach (is_array($tags) ? $tags : [] as $name) {
            if (!is_string($name)) {
                continue;
            }
            if (!TagLimits::nameFits($name)) {
                $summary['skipped']++;
                continue;
            }
            $names[] = $name;
        }

        if (count($names) > TagLimits::MAX_PER_SUBSCRIPTION) {
            $summary['tags_trimmed']++;
            $names = array_slice($names, 0, TagLimits::MAX_PER_SUBSCRIPTION);
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $categoryIds
     * @param array<string, int> $paymentMethodIds
     * @param array<string, int> $owners
     * @return array<string, mixed>
     */
    private function subscriptionInput(
        Scope $scope,
        array $row,
        array $categoryIds,
        array $paymentMethodIds,
        array $owners,
    ): array {
        $input = SubscriptionPayload::toServiceInput($row);

        $categoryName = is_string($row['category_name'] ?? null) ? NameKey::of(trim($row['category_name'])) : '';
        $input['category_id'] = $categoryIds[$categoryName] ?? '';

        // By name, like the category: the archive's `payment_method_id` is an
        // id in the household it left, and would name the wrong row here — or
        // another household's.
        $methodName = is_string($row['payment_method_name'] ?? null)
            ? NameKey::of(trim($row['payment_method_name']))
            : '';
        $input['payment_method_id'] = $paymentMethodIds[$methodName] ?? '';

        // The archive names members by email. In ISOLATED mode the service
        // forces the owner to the restoring user anyway; in SHARED mode a
        // member who is still here keeps their rows, and one who has gone
        // hands them to whoever is doing the restoring.
        $input['owner_user_id'] = $this->resolveMember($row['owner_email'] ?? null, $owners) ?? $scope->userId;
        $input['payer_user_id'] = $this->resolveMember($row['payer_email'] ?? null, $owners) ?? '';

        // Read-only in the API, and so dropped by the payload translation; a
        // restore writes it with the row. Absent from older archives.
        $input['cancelled_at'] = is_string($row['cancelled_at'] ?? null) ? $row['cancelled_at'] : '';

        // An intro offer, read-only in the API and so written here. Absent
        // from older archives, which restore as an ordinary price. An end
        // that has passed since the backup was taken is not an end the form
        // would accept, so the price comes back badged with its end unknown.
        $input['is_promotional'] = ($row['price_is_promotional'] ?? false) === true ? '1' : '0';
        $endsOn = is_string($row['promo_ends_on'] ?? null)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $row['promo_ends_on'])
            : false;
        $then = $row['promo_then_price_minor'] ?? null;
        if ($input['is_promotional'] === '1' && $endsOn !== false && $endsOn > $this->clock->today() && is_int($then)) {
            $input['offer_ends_on'] = $endsOn->format('Y-m-d');
            $input['offer_then_price'] = Money::of($then, (string) ($input['currency'] ?? 'GBP'))->toDecimalString();
        }

        // The anchor is derived from the payment date on every save, except
        // the one the form's "last day of the month" sets — which a restore
        // has to ask for, or a row billed on 30 April would come back
        // anchored to the 30th. Only for a monthly row on a month end, the
        // one place it can have been set, so an old archive cannot fail.
        $date = is_string($row['next_payment_date'] ?? null)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $row['next_payment_date'])
            : false;
        if (
            ($row['anchor_day'] ?? null) === BillingCycle::LAST_DAY_ANCHOR
            && ($row['billing_cycle'] ?? null) === BillingCycle::Monthly->value
            && $date !== false
            && $date->format('j') === $date->format('t')
        ) {
            $input['anchor_last_day'] = '1';
        }

        return $input;
    }

    /**
     * @param mixed $rows
     * @param array<string, int> $categoryIds
     * @param array<string, int> $owners
     * @param RestoreSummary $summary
     */
    private function restoreBudgets(
        Scope $scope,
        mixed $rows,
        array $categoryIds,
        array $owners,
        array &$summary,
    ): void {
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $categoryName = is_string($row['category_name'] ?? null) ? NameKey::of(trim($row['category_name'])) : '';

            try {
                $this->budgets->create($scope, [
                    'name' => (string) ($row['name'] ?? ''),
                    'amount' => $this->decimal($row['amount_minor'] ?? null, (string) ($row['currency'] ?? 'GBP')),
                    'currency' => (string) ($row['currency'] ?? ''),
                    'period' => (string) ($row['period'] ?? ''),
                    'category_id' => $categoryIds[$categoryName] ?? '',
                    'warn_threshold_percent' => (string) ($row['warn_threshold_percent'] ?? ''),
                    'is_active' => ($row['is_active'] ?? true) ? '1' : '0',
                    'owner_user_id' => $this->resolveMember($row['owner_email'] ?? null, $owners) ?? $scope->userId,
                    'subject_user_id' => $this->budgetSubject($row, $owners),
                ]);
                $summary['budgets']++;
            } catch (ValidationException) {
                $summary['skipped']++;
            }
        }
    }

    /**
     * Put the payment ledger back, row for row. A date its subscription
     * already has a charge on — the catch-up ran during the restore — keeps
     * the one there.
     *
     * @param mixed $rows
     * @param array<int, int> $subscriptionIds
     * @param RestoreSummary $summary
     * @return array<int, int> Archive index => new charge id.
     */
    private function restoreCharges(Scope $scope, mixed $rows, array $subscriptionIds, array &$summary): array
    {
        $ids = [];
        $subscriptions = [];
        $taken = [];

        foreach (is_array($rows) ? $rows : [] as $index => $row) {
            if (!is_array($row)) {
                $summary['skipped']++;
                continue;
            }

            $subscriptionId = $subscriptionIds[(int) ($row['subscription_index'] ?? -1)] ?? null;
            $date = $this->date($row['due_date'] ?? null);
            $currency = is_string($row['currency'] ?? null) ? Currency::normalise($row['currency']) : '';
            $expected = is_int($row['expected_minor'] ?? null) ? $row['expected_minor'] : null;
            $status = is_string($row['status'] ?? null) ? ChargeStatus::tryFrom($row['status']) : null;
            $source = is_string($row['source'] ?? null) ? ChargeSource::tryFrom($row['source']) : null;
            $actual = is_int($row['actual_minor'] ?? null) ? $row['actual_minor'] : null;
            // Absent from an archive made before Phase 36, which is a charge
            // with no reference.
            $reference = is_int($row['reference_minor'] ?? null) ? $row['reference_minor'] : null;

            if (
                $subscriptionId === null
                || $date === null
                || !Currency::isValidCode($currency)
                || $expected === null
                || $expected < 0
                || ($actual !== null && $actual < 0)
                || ($reference !== null && $reference < 0)
                || $status === null
                || $source === null
            ) {
                $summary['skipped']++;
                continue;
            }

            $subscription = $subscriptions[$subscriptionId] ??= $this->subscriptions->find($scope, $subscriptionId);
            $taken[$subscriptionId] ??= $this->charges->existingDates($scope, $subscriptionId);
            if ($subscription === null || isset($taken[$subscriptionId][$date->format('Y-m-d')])) {
                $summary['skipped']++;
                continue;
            }

            $note = is_string($row['note'] ?? null) && $row['note'] !== ''
                ? mb_substr($row['note'], 0, 255)
                : null;

            $ids[(int) $index] = $this->charges->restore(
                $scope,
                $subscriptionId,
                $subscription->ownerUserId,
                $date,
                Money::of($expected, $currency),
                $status === ChargeStatus::Skipped ? null : $actual,
                $status,
                $source,
                $note,
                $reference,
            );
            $taken[$subscriptionId][$date->format('Y-m-d')] = $source;
            $summary['charges']++;
        }

        return $ids;
    }

    /**
     * @param mixed $rows
     * @param array<int, int> $subscriptionIds
     * @param array<int, int> $chargeIds
     * @param list<array{kind: string, path: string}> $written
     * @param RestoreSummary $summary
     */
    private function restoreAttachments(
        Scope $scope,
        ZipArchive $zip,
        mixed $rows,
        array $subscriptionIds,
        array $chargeIds,
        array &$written,
        array &$summary,
    ): void {
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !is_string($row['entry'] ?? null)) {
                continue;
            }

            $subscriptionId = $subscriptionIds[(int) ($row['subscription_index'] ?? -1)] ?? null;
            if ($subscriptionId === null) {
                $summary['skipped']++;
                continue;
            }

            $subscription = $this->subscriptions->find($scope, $subscriptionId);
            if ($subscription === null) {
                $summary['skipped']++;
                continue;
            }

            $temporary = $this->extractToTemporaryFile($zip, $row['entry']);
            if ($temporary === null) {
                $summary['skipped']++;
                continue;
            }

            try {
                // Re-validated by magic bytes, exactly like an upload. An
                // archive is untrusted input however it was produced.
                $stored = $this->attachmentStorage->storeFile($temporary, $subscription->householdId);
                $written[] = ['kind' => 'attachment', 'path' => $stored['path']];

                $this->attachments->create(
                    $scope,
                    $subscriptionId,
                    $subscription->ownerUserId,
                    $this->date($row['period_date'] ?? null),
                    $this->restoredFilename($row['filename'] ?? null),
                    $stored['path'],
                    $stored['mime'],
                    $stored['size'],
                    $scope->userId,
                    is_int($row['charge_index'] ?? null) ? ($chargeIds[$row['charge_index']] ?? null) : null,
                );
                $summary['attachments']++;
            } catch (ValidationException) {
                $summary['skipped']++;
            } finally {
                @unlink($temporary);
            }
        }
    }

    /**
     * An archive's filename, made safe to store and to echo back in a
     * download header: no path, no control characters, at most 255
     * characters. An overlong one used to fail the insert part-way through a
     * restore (K16).
     */
    private function restoredFilename(mixed $name): string
    {
        $name = is_string($name) ? $name : '';
        $name = (string) preg_replace('/[\x00-\x1F\x7F"]/', '', str_replace(['/', '\\', "\0"], '-', $name));
        $name = basename(trim($name));

        return $name === '' || $name === '.' || $name === '..' ? 'attachment' : mb_substr($name, 0, 255);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{kind: string, path: string}> $written
     */
    private function restoreLogo(ZipArchive $zip, array $row, array &$written): ?string
    {
        $entry = $row['logo_entry'] ?? null;
        if (!is_string($entry) || $entry === '') {
            return null;
        }

        $temporary = $this->extractToTemporaryFile($zip, $entry);
        if ($temporary === null) {
            return null;
        }

        try {
            // LogoStorage validates the image type from the bytes; a file that
            // is not really an image is dropped and the subscription restores
            // without it.
            $stored = $this->logos->storeFile($temporary);
            if ($stored !== null) {
                $written[] = ['kind' => 'logo', 'path' => $stored];
            }

            return $stored;
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Refuse an archive whose entries are not all names this application would
     * have written.
     *
     * This runs before a single byte is extracted. `..` and absolute paths are
     * the classic zip-slip payload, but the check is an allow-list rather than a
     * list of things to reject, so a name nobody has thought of yet is also
     * refused.
     *
     * @throws ValidationException
     */
    private function assertSafeEntries(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw ValidationException::field('file', 'error.backup.too_many_files');
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (!is_string($name) || preg_match(self::ENTRY_PATTERN, $name) !== 1) {
                throw ValidationException::field(
                    'file',
                    'error.backup.unexpected_file',
                );
            }
        }
    }

    private function extractToTemporaryFile(ZipArchive $zip, string $entry): ?string
    {
        if (preg_match(self::ENTRY_PATTERN, $entry) !== 1) {
            return null;
        }

        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) {
            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'renovo-restore');
        if ($temporary === false) {
            fclose($stream);

            return null;
        }

        $out = fopen($temporary, 'wb');
        if ($out === false) {
            fclose($stream);

            return null;
        }

        stream_copy_to_stream($stream, $out, $this->attachmentStorage->maxBytes() + 1);
        fclose($out);
        fclose($stream);

        return $temporary;
    }

    /**
     * @param array<string, string> $emails
     * @param array<string, string> $logos Stored path => archive entry name.
     * @param array{ends_on: DateTimeImmutable|null, then: Money|null}|null $promotion
     * @return array<string, mixed>
     */
    private function exportSubscription(
        Subscription $subscription,
        DateTimeImmutable $today,
        array $emails,
        array &$logos,
        ?array $promotion,
    ): array {
        $row = Resource::subscription($subscription, $today, $promotion);

        // Ids mean nothing in another instance; email addresses do.
        $row['owner_email'] = $emails[(string) $subscription->ownerUserId] ?? null;
        $row['payer_email'] = $subscription->payerUserId === null
            ? null
            : ($emails[(string) $subscription->payerUserId] ?? null);

        if ($subscription->logoPath !== null && $this->logoAbsolutePath($subscription->logoPath) !== null) {
            $entry = 'files/logos/' . basename($subscription->logoPath);
            $logos[$subscription->logoPath] = $entry;
            $row['logo_entry'] = $entry;
        }

        return $row;
    }

    /**
     * A payment method as the archive holds it: by name, like a category, with
     * its logo as an entry beside the subscriptions' logos.
     *
     * @param array<string, string> $logos Stored path => archive entry name.
     * @return array<string, mixed>
     */
    private function exportPaymentMethod(PaymentMethod $method, array &$logos): array
    {
        $row = [
            'name' => $method->name,
            'colour' => $method->colour,
            'icon' => $method->icon,
        ];

        if ($method->logoPath !== null && $this->logoAbsolutePath($method->logoPath) !== null) {
            $entry = 'files/logos/' . basename($method->logoPath);
            $logos[$method->logoPath] = $entry;
            $row['logo_entry'] = $entry;
        }

        return $row;
    }

    /**
     * @param array<string, string> $emails
     * @return array<string, mixed>
     */
    private function exportBudget(Budget $budget, array $emails): array
    {
        return [
            'name' => $budget->name,
            'category_name' => $budget->categoryName,
            'period' => $budget->period->value,
            'amount_minor' => $budget->amount->amountMinor,
            'currency' => $budget->amount->currency,
            'warn_threshold_percent' => $budget->warnThresholdPercent,
            'is_active' => $budget->isActive,
            'owner_email' => $emails[(string) $budget->ownerUserId] ?? null,
            // Null when the budget measures the whole household.
            'subject_email' => $budget->subjectUserId === null
                ? null
                : ($emails[(string) $budget->subjectUserId] ?? null),
            'is_household' => $budget->isHousehold(),
        ];
    }

    /**
     * Whose spending a restored budget measures, as BudgetService reads it:
     * a member id, `household`, or empty for "the owner".
     *
     * An archive written before budgets had a subject has neither key, and its
     * budgets measured their owner — which empty restores. A subject who is
     * no longer a member falls back the same way rather than failing the row.
     *
     * @param array<string, mixed> $row
     * @param array<string, int>   $owners
     */
    private function budgetSubject(array $row, array $owners): string
    {
        if (($row['is_household'] ?? false) === true) {
            return BudgetService::SUBJECT_HOUSEHOLD;
        }

        return (string) ($this->resolveMember($row['subject_email'] ?? null, $owners) ?? '');
    }

    /**
     * @return array{entry: string, filename: string, period_date: string|null,
     *               subscription_index: int}|null
     */
    private function addAttachment(ZipArchive $zip, Attachment $attachment, int $position): ?array
    {
        $absolute = $this->attachmentStorage->absolutePath($attachment->storedPath);
        if ($absolute === null) {
            return null;
        }

        $entry = 'files/attachments/' . $attachment->id . '-' . basename($attachment->storedPath);
        $zip->addFile($absolute, $entry);

        return [
            'entry' => $entry,
            'filename' => $attachment->originalFilename,
            'period_date' => $attachment->periodDate?->format('Y-m-d'),
            'subscription_index' => $position,
        ];
    }

    /**
     * @return array<string, string> User id (as a string key) => email.
     */
    private function memberEmails(Scope $scope): array
    {
        $emails = [];
        foreach ($this->householdMembers($scope) as $member) {
            $emails[(string) $member['id']] = (string) $member['email'];
        }

        return $emails;
    }

    /**
     * @return array<string, int> Lower-cased email => user id.
     */
    private function memberIdsByEmail(Scope $scope): array
    {
        $ids = [];
        foreach ($this->householdMembers($scope) as $member) {
            $ids[mb_strtolower((string) $member['email'])] = (int) $member['id'];
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function householdMembers(Scope $scope): array
    {
        if (!$scope->hasHousehold()) {
            return [];
        }

        return $this->memberships->findMembersOfHousehold((int) $scope->householdId);
    }

    /**
     * @param array<string, int> $owners
     */
    private function resolveMember(mixed $email, array $owners): ?int
    {
        if (!is_string($email) || $email === '') {
            return null;
        }

        return $owners[mb_strtolower($email)] ?? null;
    }

    private function decimal(mixed $minor, string $currency): string
    {
        if (!is_numeric($minor)) {
            return '';
        }

        return Currency::isValidCode(Currency::normalise($currency))
            ? Money::of((int) $minor, $currency)->toDecimalString()
            : (string) (int) $minor;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date === false ? null : $date;
    }

    private function logoAbsolutePath(string $storedPath): ?string
    {
        if (!str_starts_with($storedPath, 'assets/logos/')) {
            return null;
        }

        $name = basename($storedPath);
        if (preg_match('/^[0-9a-f]{32}\.(png|jpg|gif|webp)$/', $name) !== 1) {
            return null;
        }

        $absolute = rtrim($this->logoDirectory, '/') . '/' . $name;

        return is_file($absolute) ? $absolute : null;
    }

    /**
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function readJson(ZipArchive $zip, string $entry): array
    {
        $contents = $zip->getFromName($entry);
        if ($contents === false) {
            throw ValidationException::field('file', 'error.backup.missing_entry', ['entry' => $entry]);
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw ValidationException::field('file', 'error.backup.invalid_json', ['entry' => $entry]);
        }

        return $decoded;
    }

    /**
     * Undo the files a failed restore had already written.
     *
     * @param list<array{kind: string, path: string}> $written
     */
    private function removeWritten(array $written): void
    {
        foreach ($written as $file) {
            if ($file['kind'] === 'attachment') {
                $this->attachmentStorage->delete($file['path']);
            } else {
                $this->logos->delete($file['path']);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
