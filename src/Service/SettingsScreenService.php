<?php

declare(strict_types=1);

namespace App\Service;

use App\Application\AppVersion;
use App\Service\Update\UpdateCheckService;
use App\Service\Update\UpdateStatus;
use App\Service\Update\UpdateUnavailable;
use App\Domain\BackupSchedule;
use App\Domain\Currency;
use App\Domain\Entity\BackupRun;
use App\Domain\Entity\AuditEntry;
use App\Domain\Entity\Category;
use App\Domain\Entity\Household;
use App\Domain\Entity\PaymentMethod;
use App\Domain\Entity\Tag;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Permission;
use App\Domain\Entity\User;
use App\Repository\HouseholdRepository;
use App\Security\KeyRing;
use App\Security\PermissionService;
use App\Security\Scope;
use App\Service\ExchangeRate\ExchangeRateProvider;
use App\Service\Backup\BackupRetention;
use App\Service\Backup\BackupAlertService;
use App\Service\Backup\BackupDestinationService;
use App\Service\Backup\BackupRehearsalService;
use App\Service\Backup\InstanceBackupService;
use App\Service\Backup\OffsiteBackupService;
use App\Domain\BackupDestinationType;
use App\Domain\BackupRehearsalSchedule;
use App\Domain\Entity\BackupCopy;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use DateTimeImmutable;
use App\Domain\TokenScope;
use App\Domain\TokenScopes;
use App\Support\Clock;

/**
 * The Settings page's three tabs: what each one shows, for whoever is looking.
 *
 * Nothing here decides anything. Each figure comes from the service that owns
 * it — the rates from `ExchangeRateService`, the counts from the same scoped
 * subscription read the list makes, the recent activity from the log's own
 * paging — and what this adds is the gathering, so that a form that fails
 * validation on any section can redraw the whole tab around its errors.
 *
 * Each section is gathered only for a reader who may see it. The template
 * also asks, to decide what to draw; asking here as well means a section a
 * reader may not see is never handed to the page in the first place.
 *
 * @phpstan-type RateRow array{currency: string, rate: string}
 * @phpstan-type Counted array{item: Category|Tag, count: int}
 */
final class SettingsScreenService
{
    /** How many audit entries the Data & integrations tab shows. */
    public const RECENT_ACTIVITY = 5;

    public function __construct(
        private readonly InstanceSettingsService $settings,
        private readonly HouseholdRepository $households,
        private readonly ExchangeRateService $rates,
        private readonly ExchangeRateProviderRegistry $rateProviders,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
        private readonly PaymentMethodService $paymentMethods,
        private readonly SubscriptionService $subscriptions,
        private readonly ApiTokenService $tokens,
        private readonly BackupService $backups,
        private readonly AuditLogService $audit,
        private readonly TrustedHostService $trustedHosts,
        private readonly PermissionService $permissions,
        private readonly UpdateCheckService $updates,
        private readonly AppVersion $version,
        private readonly KeyRing $keys,
        private readonly InstanceBackupService $instanceBackups,
        private readonly BackupDestinationService $backupDestinations,
        private readonly OffsiteBackupService $offsite,
        private readonly BackupRehearsalService $rehearsals,
        private readonly BackupAlertService $backupAlerts,
        private readonly Clock $clock,
        private readonly string $smtpHost = '',
        private readonly int $smtpPort = 0,
        private readonly string $smtpEncryption = 'none',
        private readonly bool $smtpAuthenticates = false,
        private readonly bool $metricsEnabled = false,
    ) {
    }

    /**
     * General: the household, its currency and rates, and its lists.
     *
     * @return array{
     *     household: Household|null,
     *     base_currency: string,
     *     currencies: list<string>,
     *     rate_providers: list<ExchangeRateProvider>,
     *     rates: array{
     *         provider: string,
     *         provider_label: string,
     *         rows: list<RateRow>,
     *         others: int,
     *         last_refreshed_at: DateTimeImmutable|null,
     *         is_stale: bool,
     *         is_misconfigured: bool,
     *         retry_after: DateTimeImmutable|null
     *     },
     *     categories: list<Counted>,
     *     tags: list<Counted>,
     *     payment_methods: list<PaymentMethod>
     * }
     */
    public function general(Scope $scope): array
    {
        $mayList = $scope->hasHousehold() && $this->permissions->allows($scope, Permission::ViewSubscriptions);
        $subscriptions = $mayList ? $this->subscriptions->allForStats($scope, false) : [];

        $categoryCounts = [];
        $tagCounts = [];
        $inUse = [];
        foreach ($subscriptions as $subscription) {
            if ($subscription->categoryId !== null) {
                $categoryCounts[$subscription->categoryId] = ($categoryCounts[$subscription->categoryId] ?? 0) + 1;
            }
            foreach ($subscription->tags as $tag) {
                $tagCounts[$tag->id] = ($tagCounts[$tag->id] ?? 0) + 1;
            }
            $inUse[$subscription->price->currency] = true;
        }

        return [
            'household' => $scope->hasHousehold() ? $this->households->findById((int) $scope->householdId) : null,
            'base_currency' => $this->settings->baseCurrency(),
            'currencies' => Currency::preferredFirst(),
            'rate_providers' => $this->rateProviders->all(),
            'rates' => $this->rates(array_keys($inUse)),
            'categories' => array_map(
                static fn (Category $category): array => [
                    'item' => $category,
                    'count' => $categoryCounts[$category->id] ?? 0,
                ],
                $mayList ? $this->categories->all($scope) : [],
            ),
            'tags' => array_map(
                static fn (Tag $tag): array => ['item' => $tag, 'count' => $tagCounts[$tag->id] ?? 0],
                $mayList ? $this->tags->all($scope) : [],
            ),
            'payment_methods' => $mayList ? $this->paymentMethods->all($scope) : [],
        ];
    }

    /**
     * Data & integrations: moving data in and out, and who has been doing
     * what.
     *
     * API tokens are the one section every member has: a token is its
     * issuer's own and can never do more than they can.
     *
     * @return array{
     *     tokens: list<\App\Domain\Entity\ApiToken>,
     *     token_form: array<string, mixed>,
     *     token_now: DateTimeImmutable,
     *     backup_format_version: int,
     *     private_left_out: int,
     *     recent_activity: list<AuditEntry>
     * }
     */
    public function data(User $user, Scope $scope): array
    {
        return [
            'tokens' => $this->tokens->listFor($user),
            'token_form' => $this->tokenForm(),
            // The token list's "expired" and "expires soon" are read against
            // the application's clock, not the server's.
            'token_now' => $this->clock->now(),
            'backup_format_version' => BackupService::FORMAT_VERSION,
            'private_left_out' => $this->mayBackUp($scope) ? $this->backups->privateLeftOut($scope) : 0,
            // The head of the log the "full audit log" link opens: the same
            // read, so the two can never disagree about what this reader may
            // see.
            'recent_activity' => $this->permissions->allows($scope, Permission::ViewAuditLog)
                ? $this->audit->page($scope, [], 1, self::RECENT_ACTIVITY)
                : [],
        ];
    }

    /**
     * Instance: the settings that apply to every account on the server, and
     * what the environment says about the server itself.
     *
     * The mail relay is described and never revealed: its host, port and
     * encryption, and whether it signs in — not with what.
     *
     * @return array{
     *     isolation_mode: string,
     *     isolation_modes: list<IsolationMode>,
     *     allow_registration: bool,
     *     demo_mode: bool,
     *     trusted_hosts: list<array<string, mixed>>,
     *     smtp: array{host: string, port: int, encryption: string, authenticates: bool},
     *     metrics_enabled: bool,
     *     secrets_key_set: bool,
     *     scheduler_last_run_at: DateTimeImmutable|null,
     *     version: string,
     *     version_build: string|null,
     *     update: UpdateStatus,
     *     update_check_enabled: bool,
     *     update_banner_enabled: bool,
     *     updates_unavailable: UpdateUnavailable|null,
     *     backups: array<string, mixed>,
     *     token_max_days: int|null,
     *     tokens_without_expiry: int
     * }
     */
    public function instance(): array
    {
        return [
            'isolation_mode' => $this->settings->isolationMode()->value,
            'isolation_modes' => IsolationMode::cases(),
            'allow_registration' => $this->settings->registrationAllowed(),
            'demo_mode' => $this->settings->isDemoMode(),
            'trusted_hosts' => $this->trustedHosts->all(),
            'smtp' => [
                'host' => $this->smtpHost,
                'port' => $this->smtpPort,
                'encryption' => $this->smtpEncryption,
                'authenticates' => $this->smtpAuthenticates,
            ],
            'metrics_enabled' => $this->metricsEnabled,
            // Without SECRETS_KEY, stored secrets are encrypted under
            // SESSION_KEY (or TOTP_ENCRYPTION_KEY), and rotating the session
            // key would make every channel and authenticator unreadable.
            'secrets_key_set' => $this->keys->usesSecretsKey(),
            'scheduler_last_run_at' => $this->settings->schedulerLastRunAt(),
            // The Version row and the Updates switches. Unavailable is the
            // environment ruling checks out (UPDATE_CHECK=false, or a
            // repository that is not an owner/name), whatever the setting.
            'version' => $this->version->current(),
            'version_build' => $this->version->build(),
            'update' => $this->updates->status(),
            'update_check_enabled' => $this->settings->updateCheckEnabled(),
            'update_banner_enabled' => $this->settings->updateBannerEnabled(),
            'updates_unavailable' => $this->updates->unavailableReason(),
            'backups' => $this->instanceBackups(),
            // Phase 42: a maximum lifetime binds only tokens issued after it,
            // so the card says how many live tokens still never expire.
            'token_max_days' => $this->tokens->maxDays(),
            'tokens_without_expiry' => $this->tokens->countWithoutExpiry(),
        ];
    }

    /**
     * The issue form's choices (Phase 42): the scope presets and the scopes a
     * custom token picks from, and the lifetimes — 90 days to start with, and
     * no "No expiry" while the instance has a maximum.
     *
     * @return array{
     *     presets: list<string>,
     *     scopes: list<TokenScope>,
     *     lifetimes: list<int>,
     *     default_lifetime: int,
     *     max_days: int|null
     * }
     */
    private function tokenForm(): array
    {
        $max = $this->tokens->maxDays();

        // Only the lifetimes the maximum allows, and the maximum itself as a
        // choice when it is none of them, so the default is always a chip.
        $lifetimes = array_values(array_filter(
            ApiTokenService::LIFETIME_CHOICES,
            static fn (int $days): bool => $max === null || $days <= $max,
        ));
        if ($max !== null && !in_array($max, $lifetimes, true)) {
            $lifetimes[] = $max;
            sort($lifetimes);
        }

        return [
            'presets' => TokenScopes::PRESETS,
            'scopes' => TokenScope::cases(),
            'lifetimes' => $lifetimes,
            'default_lifetime' => $this->tokens->defaultLifetimeDays(),
            'max_days' => $max,
        ];
    }

    /**
     * The Instance backups card (Phase 38): the key's fingerprint, never the
     * key; the schedule and retention; and the last runs, each with its size
     * ready to print.
     *
     * @return array{
     *     key_state: string,
     *     fingerprint: string|null,
     *     directory: string,
     *     schedule: string,
     *     schedules: list<BackupSchedule>,
     *     retention: BackupRetention,
     *     max_retention: int,
     *     last_verified: BackupRun|null,
     *     local_only: bool,
     *     offsite: bool,
     *     stale: bool,
     *     history: list<array{
     *         run: BackupRun,
     *         size: array{key: string, value: float, digits: int}|null,
     *         copies: list<array{name: string|null, status: string}>,
     *         offsite: string|null
     *     }>,
     *     destinations: list<array{
     *         record: \App\Domain\Entity\BackupDestinationRecord,
     *         last_upload: DateTimeImmutable|null
     *     }>,
     *     destination_types: list<BackupDestinationType>,
     *     rehearsal: array<string, mixed>
     * }
     */
    private function instanceBackups(): array
    {
        // Phase 39: where each run was sent, by destination name.
        $destinations = $this->backupDestinations->all();
        $names = [];
        foreach ($destinations as $destination) {
            $names[$destination->id] = $destination->name;
        }
        $runs = $this->instanceBackups->history();
        $copies = $this->offsite->copiesFor($runs);
        $uploads = $this->offsite->lastUploads();

        $history = array_map(static fn (BackupRun $run): array => [
            'run' => $run,
            'size' => $run->sizeBytes === null ? null : self::size($run->sizeBytes),
            'copies' => array_map(static fn (BackupCopy $copy): array => [
                'name' => $copy->destinationId === null ? null : ($names[$copy->destinationId] ?? null),
                'status' => $copy->status,
            ], $copies[$run->id] ?? []),
            'offsite' => self::offsiteState($copies[$run->id] ?? []),
        ], $runs);
        $offsite = $this->offsite->hasOffsiteCopy();

        return [
            'key_state' => $this->instanceBackups->keyState(),
            'fingerprint' => $this->instanceBackups->keyFingerprint(),
            'directory' => $this->instanceBackups->directory(),
            'schedule' => $this->settings->backupSchedule()->value,
            'schedules' => BackupSchedule::cases(),
            'retention' => $this->settings->backupRetention(),
            'max_retention' => BackupRetention::MAX,
            'last_verified' => $this->instanceBackups->lastVerified(),
            // The "only on this server" warning clears once a destination
            // holds a confirmed copy.
            'local_only' => $this->instanceBackups->hasLocalBackups() && !$offsite,
            'offsite' => $offsite,
            'stale' => $this->backupAlerts->isStale(),
            'history' => $history,
            'destinations' => array_map(static fn ($record): array => [
                'record' => $record,
                'last_upload' => $uploads[$record->id] ?? null,
            ], $destinations),
            'destination_types' => BackupDestinationType::cases(),
            'rehearsal' => [
                'schedule' => $this->rehearsals->schedule()->value,
                'schedules' => BackupRehearsalSchedule::cases(),
                'scratch' => $this->rehearsals->hasScratchDatabase(),
                'last' => $this->rehearsals->last(),
                'history' => $this->rehearsals->history(5),
                'has_destination' => $destinations !== [],
            ],
        ];
    }

    /**
     * Where a run stands off the server: stored once any destination has
     * confirmed it, sending while a copy waits, only here when every copy
     * failed, and null when it was never sent anywhere.
     *
     * @param list<BackupCopy> $copies
     * @return 'stored'|'sending'|'local'|null
     */
    private static function offsiteState(array $copies): ?string
    {
        if ($copies === []) {
            return null;
        }

        $statuses = array_map(static fn (BackupCopy $copy): string => $copy->status, $copies);
        if (in_array(BackupCopy::CONFIRMED, $statuses, true) || in_array(BackupCopy::PRUNED, $statuses, true)) {
            return 'stored';
        }

        return in_array(BackupCopy::PENDING, $statuses, true) ? 'sending' : 'local';
    }

    /**
     * A file size as the card prints it: kilobytes, or megabytes to one
     * place once there are enough of them.
     *
     * @return array{key: string, value: float, digits: int}
     */
    private static function size(int $bytes): array
    {
        $kilobytes = $bytes / 1024;

        return $kilobytes < 1024
            ? ['key' => 'backup.size_kb', 'value' => max(1.0, ceil($kilobytes)), 'digits' => 0]
            : ['key' => 'backup.size_mb', 'value' => round($kilobytes / 1024, 1), 'digits' => 1];
    }

    private function mayBackUp(Scope $scope): bool
    {
        return $scope->hasHousehold() && $this->permissions->allows($scope, Permission::ManageBackups);
    }

    /**
     * The rate table as the General tab draws it: how much one unit of each
     * currency the household pays in is worth in the base, to four places.
     *
     * Only the currencies in use are listed. A provider publishes thirty or
     * more, and the ones that matter to a reader are the ones their bills are
     * in; the rest are counted, not listed.
     *
     * @param list<string> $inUse
     * @return array{
     *     provider: string,
     *     provider_label: string,
     *     rows: list<RateRow>,
     *     others: int,
     *     last_refreshed_at: DateTimeImmutable|null,
     *     is_stale: bool,
     *     is_misconfigured: bool,
     *     retry_after: DateTimeImmutable|null
     * }
     */
    private function rates(array $inUse): array
    {
        $rows = [];
        $others = 0;
        foreach ($this->rates->rates() as $rate) {
            if (!in_array($rate->quoteCurrency, $inUse, true)) {
                $others++;
                continue;
            }

            $rows[] = ['currency' => $rate->quoteCurrency, 'rate' => $this->fourPlaces($rate->inverted())];
        }

        $provider = $this->rates->provider();

        return [
            'provider' => $provider->key(),
            'provider_label' => $provider->label(),
            'rows' => $rows,
            'others' => $others,
            'last_refreshed_at' => $this->rates->lastRefreshedAt(),
            'is_stale' => $this->rates->isStale(),
            'is_misconfigured' => $this->rates->isMisconfigured(),
            'retry_after' => $this->rates->retryAfter(),
        ];
    }

    /**
     * A rate to four decimal places, rounded half up, in integers throughout:
     * the table is scaled by 10^8, so four places is the scaled value over
     * 10^4.
     */
    private function fourPlaces(ExchangeRate $rate): string
    {
        $divisor = 10 ** (ExchangeRate::SCALE_EXPONENT - 4);
        $rounded = intdiv($rate->rateScaled + intdiv($divisor, 2), $divisor);

        return sprintf('%d.%04d', intdiv($rounded, 10_000), $rounded % 10_000);
    }
}
