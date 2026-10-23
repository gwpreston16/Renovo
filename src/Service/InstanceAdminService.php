<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Currency;
use App\Domain\Entity\User;
use App\Domain\IsolationMode;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;

/**
 * Instance-wide settings, applied as one operation.
 *
 * The controller used to do this inline. It moved here for the audit log, and
 * the result is better for a second reason: the list of what actually changed
 * is computed against the stored values rather than assumed from the form, so a
 * save that alters nothing records nothing, and the entry that is written names
 * the settings that moved.
 *
 * The rate-provider API key is never included in that list by value. That it
 * was set is worth recording; what it was set to is not something the audit log
 * should be carrying.
 */
final class InstanceAdminService
{
    public function __construct(
        private readonly InstanceSettingsService $settings,
        private readonly ExchangeRateService $rates,
        private readonly ExchangeRateProviderRegistry $providers,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @param array{
     *     base_currency?: string,
     *     isolation_mode?: string,
     *     allow_registration?: bool,
     *     rate_provider?: string,
     *     rate_provider_key?: string,
     *     clear_rate_provider_key?: bool,
     *     demo_mode?: bool,
     *     update_check_enabled?: bool,
     *     update_banner_enabled?: bool,
     * } $input
     * @return list<string> The settings that changed.
     */
    public function apply(User $actor, array $input): array
    {
        $changes = [];

        $currency = Currency::normalise($input['base_currency'] ?? '');
        $baseCurrencyChanged = Currency::isValidCode($currency) && $currency !== $this->settings->baseCurrency();
        if ($baseCurrencyChanged) {
            $this->settings->setBaseCurrency($currency);
            $changes[] = 'base_currency';
        }

        $providerChanged = $this->applyProvider($input, $changes);

        // Cached rates are stored against one base and sourced from one
        // provider. Changing either makes every cached row answer a question
        // nobody asked, so they are dropped rather than left to expire.
        if ($baseCurrencyChanged || $providerChanged) {
            $this->rates->invalidate();
        }

        $isolation = IsolationMode::tryFrom($input['isolation_mode'] ?? '');
        if ($isolation !== null && $isolation !== $this->settings->isolationMode()) {
            $this->settings->setIsolationMode($isolation);
            $changes[] = 'isolation_mode';
        }

        // The settings live on two tabs since Phase 28 — the currency and the
        // rates on General, the rest on Instance — and each tab's form posts
        // only its own. A switch that was not posted has not been asked
        // about, so it is left as it is rather than read as "off".
        $allowRegistration = $input['allow_registration'] ?? null;
        if ($allowRegistration !== null && $allowRegistration !== $this->settings->registrationAllowed()) {
            $this->settings->setRegistrationAllowed($allowRegistration);
            $changes[] = 'allow_registration';
        }

        // Turning demo mode on makes the instance read-only, including this
        // form. The middleware keeps this one route open to an instance
        // administrator so that the switch can be flipped back.
        $demoMode = $input['demo_mode'] ?? null;
        if ($demoMode !== null && $demoMode !== $this->settings->isDemoMode()) {
            $this->settings->setDemoMode($demoMode);
            $changes[] = 'demo_mode';
        }

        $updateCheck = $input['update_check_enabled'] ?? null;
        if ($updateCheck !== null && $updateCheck !== $this->settings->updateCheckEnabled()) {
            $this->settings->setUpdateCheckEnabled($updateCheck);
            $changes[] = 'update_check_enabled';
        }

        $updateBanner = $input['update_banner_enabled'] ?? null;
        if ($updateBanner !== null && $updateBanner !== $this->settings->updateBannerEnabled()) {
            $this->settings->setUpdateBannerEnabled($updateBanner);
            $changes[] = 'update_banner_enabled';
        }

        if ($changes !== []) {
            $this->audit->record(AuditAction::InstanceSettingsChanged, $actor, ['changed' => $changes]);
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $changes
     * @return bool Whether the provider itself changed.
     */
    private function applyProvider(array $input, array &$changes): bool
    {
        $requested = is_string($input['rate_provider'] ?? null) ? $input['rate_provider'] : '';
        $changed = false;

        if ($this->providers->has($requested) && $requested !== $this->rates->provider()->key()) {
            $this->settings->setRateProvider($requested);
            $changes[] = 'rate_provider';
            $changed = true;
        }

        // An empty field leaves the stored key alone: the input is rendered
        // blank every time (it is a secret and is never echoed back), so
        // treating blank as "clear it" would wipe the key on every save.
        $key = trim(is_string($input['rate_provider_key'] ?? null) ? $input['rate_provider_key'] : '');
        if ($key !== '') {
            $this->settings->setRateProviderKey($key);
            $changes[] = 'rate_provider_key';
        } elseif (($input['clear_rate_provider_key'] ?? false) === true) {
            $this->settings->setRateProviderKey('');
            $changes[] = 'rate_provider_key';
        }

        return $changed;
    }
}
