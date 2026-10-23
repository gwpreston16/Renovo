<?php

declare(strict_types=1);

namespace App\Service;

use App\Application\AppVersion;
use App\Service\Update\UpdateCheckService;
use App\Service\Update\UpdateStatus;
use App\Service\Update\UpdateUnavailable;
use App\Domain\Currency;
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
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use DateTimeImmutable;

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
     *     backup_format_version: int,
     *     private_left_out: int,
     *     recent_activity: list<AuditEntry>
     * }
     */
    public function data(User $user, Scope $scope): array
    {
        return [
            'tokens' => $this->tokens->listFor($user),
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
     *     updates_unavailable: UpdateUnavailable|null
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
        ];
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
