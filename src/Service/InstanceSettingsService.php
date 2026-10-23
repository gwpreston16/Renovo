<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Currency;
use App\Domain\IsolationMode;
use App\Repository\InstanceSettingsRepository;
use DateTimeImmutable;

/**
 * Reads and writes the handful of instance-wide settings.
 *
 * Values are cached for the lifetime of the request: the isolation mode is
 * consulted on every scoped query, and it must not cost a round trip each
 * time.
 */
final class InstanceSettingsService
{
    public const KEY_ISOLATION_MODE = 'isolation_mode';
    public const KEY_BASE_CURRENCY = 'base_currency';
    public const KEY_SETUP_COMPLETED_AT = 'setup_completed_at';
    public const KEY_INSTANCE_NAME = 'instance_name';
    public const KEY_ALLOW_REGISTRATION = 'allow_registration';
    public const KEY_RATE_PROVIDER = 'rate_provider';
    public const KEY_RATE_PROVIDER_KEY = 'rate_provider_key';
    public const KEY_RATES_LAST_ATTEMPT_AT = 'rates_last_attempt_at';
    public const KEY_NOTIFICATIONS_SETUP_AT = 'notifications_setup_at';
    public const KEY_SCHEDULER_LAST_RUN_AT = 'scheduler_last_run_at';
    public const KEY_DEMO_MODE = 'demo_mode';
    public const KEY_UPDATE_CHECK_ENABLED = 'update_check_enabled';
    public const KEY_UPDATE_BANNER_ENABLED = 'update_banner_enabled';
    public const KEY_UPDATE_LATEST_VERSION = 'update_latest_version';
    public const KEY_UPDATE_LATEST_URL = 'update_latest_url';
    public const KEY_UPDATE_LATEST_PUBLISHED_AT = 'update_latest_published_at';
    public const KEY_UPDATE_CHECKED_AT = 'update_checked_at';
    public const KEY_UPDATE_LAST_ATTEMPT_AT = 'update_last_attempt_at';

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly InstanceSettingsRepository $repository)
    {
    }

    public function isolationMode(): IsolationMode
    {
        return IsolationMode::tryFrom($this->get(self::KEY_ISOLATION_MODE, IsolationMode::Shared->value))
            ?? IsolationMode::Shared;
    }

    public function setIsolationMode(IsolationMode $mode): void
    {
        $this->set(self::KEY_ISOLATION_MODE, $mode->value);
    }

    public function baseCurrency(): string
    {
        return Currency::normalise($this->get(self::KEY_BASE_CURRENCY, 'GBP'));
    }

    public function setBaseCurrency(string $currency): void
    {
        $this->set(self::KEY_BASE_CURRENCY, Currency::normalise($currency));
    }

    public function instanceName(): string
    {
        return $this->get(self::KEY_INSTANCE_NAME, 'Renovo');
    }

    public function setInstanceName(string $name): void
    {
        $this->set(self::KEY_INSTANCE_NAME, $name);
    }

    public function registrationAllowed(): bool
    {
        return $this->get(self::KEY_ALLOW_REGISTRATION, '0') === '1';
    }

    public function setRegistrationAllowed(bool $allowed): void
    {
        $this->set(self::KEY_ALLOW_REGISTRATION, $allowed ? '1' : '0');
    }

    /**
     * The chosen exchange-rate provider's key, or null when the operator has
     * expressed no preference and the registry's default should be used.
     */
    public function rateProvider(): ?string
    {
        $value = $this->get(self::KEY_RATE_PROVIDER, '');

        return $value === '' ? null : $value;
    }

    public function setRateProvider(string $key): void
    {
        $this->set(self::KEY_RATE_PROVIDER, $key);
    }

    /**
     * The API key stored through the wizard or settings page.
     *
     * Callers must not read this directly — ExchangeRateService resolves it,
     * because an environment variable takes precedence over whatever is in the
     * database. That ordering is the standing rule about secrets applied to a
     * value the UI also has to be able to collect: an operator who would rather
     * keep the key out of the database entirely can, and their choice wins.
     */
    public function storedRateProviderKey(): string
    {
        return $this->get(self::KEY_RATE_PROVIDER_KEY, '');
    }

    public function setRateProviderKey(string $key): void
    {
        $this->set(self::KEY_RATE_PROVIDER_KEY, $key);
    }

    /**
     * When a rate refresh was last attempted, successful or not.
     *
     * Recorded so that a provider which is down is not re-tried on every page
     * view by every user.
     */
    public function ratesLastAttemptAt(): ?DateTimeImmutable
    {
        $value = $this->get(self::KEY_RATES_LAST_ATTEMPT_AT, '');

        return $value === '' ? null : new DateTimeImmutable($value);
    }

    public function markRatesAttempted(DateTimeImmutable $at): void
    {
        $this->set(self::KEY_RATES_LAST_ATTEMPT_AT, $at->format('Y-m-d H:i:s'));
    }

    /**
     * Forget the last attempt. An attempt belongs to the provider and the base
     * it was made against; once either changes, its back-off says nothing
     * about the new one.
     */
    public function clearRatesAttempted(): void
    {
        $this->set(self::KEY_RATES_LAST_ATTEMPT_AT, '');
    }

    public function isSetupComplete(): bool
    {
        return $this->get(self::KEY_SETUP_COMPLETED_AT, '') !== '';
    }

    public function markSetupComplete(string $timestamp): void
    {
        $this->set(self::KEY_SETUP_COMPLETED_AT, $timestamp);
    }

    /**
     * Whether the first-run wizard's notification step has been dealt with.
     *
     * Separate from the main setup flag because it is reached *after* the
     * instance exists: the account, the household and the isolation mode are
     * settled before anything can be configured to notify anybody. It is a
     * one-time prompt rather than a gate — an operator who skips it has an
     * instance that works and no notifications, which is a legitimate choice.
     */
    public function isNotificationSetupComplete(): bool
    {
        return $this->get(self::KEY_NOTIFICATIONS_SETUP_AT, '') !== '';
    }

    public function markNotificationSetupComplete(string $timestamp): void
    {
        $this->set(self::KEY_NOTIFICATIONS_SETUP_AT, $timestamp);
    }

    /**
     * When the scheduler last finished a run.
     *
     * Written by `reminders:run` and read by the readiness endpoint. Without
     * it an instance whose scheduler container died would look perfectly
     * healthy right up until somebody noticed they had stopped being reminded
     * about anything.
     */
    public function schedulerLastRunAt(): ?DateTimeImmutable
    {
        $value = $this->get(self::KEY_SCHEDULER_LAST_RUN_AT, '');

        return $value === '' ? null : new DateTimeImmutable($value);
    }

    public function markSchedulerRun(DateTimeImmutable $at): void
    {
        $this->set(self::KEY_SCHEDULER_LAST_RUN_AT, $at->format('Y-m-d H:i:s'));
    }

    /**
     * Whether this instance is a read-only demonstration.
     *
     * An instance-wide switch, not a per-user one: a demo is what the whole
     * server is for while it is on.
     */
    public function isDemoMode(): bool
    {
        return $this->get(self::KEY_DEMO_MODE, '0') === '1';
    }

    public function setDemoMode(bool $enabled): void
    {
        $this->set(self::KEY_DEMO_MODE, $enabled ? '1' : '0');
    }

    /**
     * Whether an instance administrator has turned on the daily check for a
     * newer release. Off until somebody does: it is a request to a third
     * party, and an instance on a LAN may have no route to one.
     */
    public function updateCheckEnabled(): bool
    {
        return $this->get(self::KEY_UPDATE_CHECK_ENABLED, '0') === '1';
    }

    public function setUpdateCheckEnabled(bool $enabled): void
    {
        $this->set(self::KEY_UPDATE_CHECK_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * Whether a newer release is announced on instance administrators'
     * dashboards. On by default: turning the check on is the opt-in.
     */
    public function updateBannerEnabled(): bool
    {
        return $this->get(self::KEY_UPDATE_BANNER_ENABLED, '1') === '1';
    }

    public function setUpdateBannerEnabled(bool $enabled): void
    {
        $this->set(self::KEY_UPDATE_BANNER_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * What the last successful check found: the latest release's version, its
     * page and when it was published — or, when the version is empty, that
     * the repository has published no release at all.
     *
     * @return array{version: string, url: string, published_at: DateTimeImmutable|null}
     */
    public function latestRelease(): array
    {
        $published = $this->get(self::KEY_UPDATE_LATEST_PUBLISHED_AT, '');

        return [
            'version' => $this->get(self::KEY_UPDATE_LATEST_VERSION, ''),
            'url' => $this->get(self::KEY_UPDATE_LATEST_URL, ''),
            'published_at' => $published === '' ? null : new DateTimeImmutable($published),
        ];
    }

    /**
     * Record a successful check. An empty version is "no release published".
     */
    public function recordLatestRelease(
        string $version,
        string $url,
        ?DateTimeImmutable $publishedAt,
        DateTimeImmutable $checkedAt,
    ): void {
        $this->set(self::KEY_UPDATE_LATEST_VERSION, $version);
        $this->set(self::KEY_UPDATE_LATEST_URL, $url);
        $this->set(self::KEY_UPDATE_LATEST_PUBLISHED_AT, $publishedAt?->format('Y-m-d H:i:s') ?? '');
        $this->set(self::KEY_UPDATE_CHECKED_AT, $checkedAt->format('Y-m-d H:i:s'));
    }

    /**
     * When a check last succeeded, or null when none ever has.
     */
    public function updateCheckedAt(): ?DateTimeImmutable
    {
        $value = $this->get(self::KEY_UPDATE_CHECKED_AT, '');

        return $value === '' ? null : new DateTimeImmutable($value);
    }

    /**
     * When a check was last attempted, successful or not — what the back-off
     * and the Check now limit are measured from.
     */
    public function updateLastAttemptAt(): ?DateTimeImmutable
    {
        $value = $this->get(self::KEY_UPDATE_LAST_ATTEMPT_AT, '');

        return $value === '' ? null : new DateTimeImmutable($value);
    }

    public function markUpdateCheckAttempted(DateTimeImmutable $at): void
    {
        $this->set(self::KEY_UPDATE_LAST_ATTEMPT_AT, $at->format('Y-m-d H:i:s'));
    }

    private function get(string $key, string $default): string
    {
        $this->cache ??= $this->repository->all();

        $value = $this->cache[$key] ?? '';

        return $value === '' ? $default : $value;
    }

    private function set(string $key, string $value): void
    {
        $this->repository->set($key, $value);

        // Only into a cache that has been loaded. Written into an empty one,
        // it would become a cache of this one key, and every other setting
        // would read as its default for the rest of the request.
        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }
}
