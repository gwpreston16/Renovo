<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Currency;
use App\Domain\ExchangeRate;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Http\OutboundContext;
use App\Http\OutboundMode;
use App\Repository\ExchangeRateRepository;
use App\Service\ExchangeRate\ExchangeRateProvider;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRate\RateProviderException;
use App\Service\ExchangeRate\RateTable;
use App\Support\Clock;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Currency conversion, and the honesty about when it is not available.
 *
 * Three things are worth understanding before changing anything here.
 *
 * **Every rate is stored against the instance's base currency.** A conversion
 * between two other currencies is a cross-rate through the base:
 * X -> Y is (base -> Y) divided by (base -> X). One row per currency rather
 * than one per pair, and a single refresh keeps all of them current.
 *
 * **A missing rate is a first-class answer.** `convert()` returns null rather
 * than throwing or substituting 1:1, and callers surface per-currency
 * subtotals instead of a combined figure. A total that silently treats a
 * hundred yen as a hundred pounds is worse than no total at all, so the
 * degraded path is the one the UI is built around and the one that is tested.
 *
 * **Refreshing never blocks a page.** The lazy refresh is best-effort: it is
 * attempted at most once per retry window, any failure is logged and swallowed,
 * and the previous cached table keeps being used. Operators who want rates kept
 * current without depending on someone loading a page run `rates:refresh` from
 * the scheduler.
 */
final class ExchangeRateService
{
    /** @var array<string, ExchangeRate>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ExchangeRateRepository $repository,
        private readonly ExchangeRateProviderRegistry $registry,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly int $ttlSeconds,
        private readonly int $retrySeconds,
        private readonly string $environmentApiKey,
        private readonly ?OutboundContext $outbound = null,
        private readonly ?OutboundMode $outboundMode = null,
    ) {
    }

    /**
     * The rate needed to turn $from into $to, or null when it cannot be
     * derived from what is cached.
     */
    public function rateFor(string $from, string $to): ?ExchangeRate
    {
        $from = Currency::normalise($from);
        $to = Currency::normalise($to);

        if ($from === $to) {
            return ExchangeRate::of($from, $to, ExchangeRate::SCALE, null, 'identity');
        }

        $base = $this->settings->baseCurrency();
        $rates = $this->cachedRates();

        $fromRate = $from === $base ? ExchangeRate::SCALE : ($rates[$from]->rateScaled ?? null);
        $toRate = $to === $base ? ExchangeRate::SCALE : ($rates[$to]->rateScaled ?? null);

        if ($fromRate === null || $toRate === null) {
            return null;
        }

        $sample = $rates[$to] ?? $rates[$from] ?? null;

        return ExchangeRate::of(
            $from,
            $to,
            Rounding::multiplyDivide($toRate, ExchangeRate::SCALE, $fromRate),
            $sample?->asOfDate,
            $sample !== null ? $sample->provider : '',
        );
    }

    /**
     * Convert an amount, or return null when no rate is available.
     */
    public function convert(Money $money, string $to): ?Money
    {
        $rate = $this->rateFor($money->currency, $to);
        if ($rate === null) {
            return null;
        }

        return Money::of($rate->convertMinor($money->amountMinor), $rate->quoteCurrency);
    }

    public function convertMinor(int $amountMinor, string $from, string $to): ?int
    {
        return $this->rateFor($from, $to)?->convertMinor($amountMinor);
    }

    /**
     * True when every one of the given currencies can reach the target.
     *
     * Callers use this to decide between a combined total and per-currency
     * subtotals, so it is deliberately all-or-nothing: a "total" covering four
     * currencies out of five, with no indication which, is a wrong number
     * rather than a partial one.
     *
     * @param list<string> $currencies
     */
    public function canCombine(array $currencies, string $target): bool
    {
        foreach ($currencies as $currency) {
            if ($this->rateFor($currency, $target) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert every amount into one currency and add them up, or null when any
     * of them has no rate.
     *
     * @param array<string, int> $amountsByCurrency
     */
    public function combine(array $amountsByCurrency, string $target): ?int
    {
        $total = 0;
        foreach ($amountsByCurrency as $currency => $amountMinor) {
            $converted = $this->convertMinor($amountMinor, (string) $currency, $target);
            if ($converted === null) {
                return null;
            }
            $total += $converted;
        }

        return $total;
    }

    /**
     * Attempt a refresh if the cache is stale, swallowing any failure.
     *
     * Called from the dashboard. It must never turn a provider outage into a
     * broken page, and it must not let a page view become a synchronous
     * network call more than once per retry window.
     *
     * @param list<string>|null $currenciesInUse The currencies the page is about
     *        to convert; when none differs from the base there is nothing to fetch.
     */
    public function refreshIfStale(?array $currenciesInUse = null): void
    {
        // Nothing to fetch for: with every amount in the base currency no rate
        // is ever read, and a page view must not pay a connect timeout to
        // refresh a table it will not use (K54).
        if ($currenciesInUse !== null && !$this->needsRates($currenciesInUse)) {
            return;
        }

        // The instance sends nothing: there is no attempt to make, and one
        // would only be refused and written to the audit log on every page.
        if ($this->outboundMode === OutboundMode::Off) {
            return;
        }

        if (!$this->isStale() || !$this->mayAttemptNow()) {
            return;
        }

        try {
            $this->refresh();
        } catch (RateProviderException $exception) {
            // Logged, not surfaced: the cached table (possibly empty) stays in
            // use and the UI falls back to per-currency subtotals.
            $this->logger->warning('Exchange-rate refresh failed.', ['reason' => $exception->getMessage()]);
        }
    }

    /**
     * The settings page's "Refresh now": a refresh asked for by a person,
     * through the same provider and the same shared HTTP client as the
     * scheduled one.
     *
     * It is refused while a failed attempt is inside its retry window — the
     * back-off that keeps a provider outage from becoming a request on every
     * page view is not something a button should be able to click through. A
     * refresh that succeeded is no such bar: asking again is what the button
     * is for.
     *
     * @return int|null Number of rates cached, or null when backing off.
     * @throws RateProviderException
     */
    public function refreshNow(?int $actorUserId = null): ?int
    {
        if ($this->retryAfter() !== null) {
            return null;
        }

        return $this->refresh($actorUserId);
    }

    /**
     * Whether any of the currencies in use is not the base, which is the only
     * case in which a conversion reads a rate.
     *
     * @param list<string> $currencies
     */
    private function needsRates(array $currencies): bool
    {
        $base = $this->settings->baseCurrency();

        foreach ($currencies as $currency) {
            if (Currency::normalise($currency) !== $base) {
                return true;
            }
        }

        return false;
    }

    /**
     * When the failure back-off lifts, or null when nothing is holding a
     * refresh back.
     */
    public function retryAfter(): ?DateTimeImmutable
    {
        $lastAttempt = $this->settings->ratesLastAttemptAt();
        if ($lastAttempt === null) {
            return null;
        }

        // A successful attempt stores its table at or after the moment it was
        // marked, so an attempt later than the last table is one that failed.
        $fetchedAt = $this->lastRefreshedAt();
        if ($fetchedAt !== null && $fetchedAt >= $lastAttempt) {
            return null;
        }

        $until = $lastAttempt->modify(sprintf('+%d seconds', $this->retrySeconds));

        return $until > $this->clock->now() ? $until : null;
    }

    /**
     * Fetch and store the current table.
     *
     * @return int Number of rates cached.
     * @throws RateProviderException
     */
    public function refresh(?int $actorUserId = null): int
    {
        $base = $this->settings->baseCurrency();
        $provider = $this->provider();

        $this->settings->markRatesAttempted($this->clock->now());

        $fetch = fn (): RateTable => $provider->fetch($base, $this->apiKey());
        $table = $this->outbound === null
            ? $fetch()
            : $this->outbound->within(OutboundContext::RATES, $actorUserId, $fetch);

        // A provider that could not serve the base we asked for answered in its
        // own; re-expressing it here is exactly why the table knows its base.
        $rebased = $table->rebasedTo($base);
        if ($rebased === null) {
            throw RateProviderException::malformed(
                $provider->label(),
                sprintf('it published %s rates, which cannot be converted to %s', $table->baseCurrency, $base),
            );
        }

        $rates = $rebased->toExchangeRates();
        $this->repository->replaceBase($base, $rates, $this->clock->now());
        $this->cache = null;

        return count($rates);
    }

    /**
     * Forget everything cached. Used when the provider or the base currency
     * changes and the stored numbers no longer came from where they should.
     */
    public function invalidate(): void
    {
        $this->repository->clear();
        $this->cache = null;

        // The back-off went with the table: it was about the old provider or
        // base. Left in place, a successful refresh just before the change
        // would read as a failure — an attempt newer than any table — and
        // hold Refresh now back at exactly the moment it is wanted.
        $this->settings->clearRatesAttempted();
    }

    public function isStale(): bool
    {
        $fetchedAt = $this->repository->lastFetchedAt($this->settings->baseCurrency());
        if ($fetchedAt === null) {
            return true;
        }

        return $fetchedAt->getTimestamp() + $this->ttlSeconds <= $this->clock->now()->getTimestamp();
    }

    public function lastRefreshedAt(): ?DateTimeImmutable
    {
        return $this->repository->lastFetchedAt($this->settings->baseCurrency());
    }

    /**
     * Currencies that can currently be converted to the base.
     *
     * @return list<string>
     */
    public function availableCurrencies(): array
    {
        $codes = array_keys($this->cachedRates());
        $base = $this->settings->baseCurrency();
        if (!in_array($base, $codes, true)) {
            $codes[] = $base;
        }

        sort($codes);

        return $codes;
    }

    /**
     * The cached table against the base currency, one rate per other
     * currency, alphabetically — what the settings page lists.
     *
     * @return list<ExchangeRate>
     */
    public function rates(): array
    {
        $rates = array_values(array_filter(
            $this->cachedRates(),
            fn (ExchangeRate $rate): bool => $rate->quoteCurrency !== $this->settings->baseCurrency(),
        ));
        usort($rates, static fn (ExchangeRate $a, ExchangeRate $b): int => $a->quoteCurrency <=> $b->quoteCurrency);

        return $rates;
    }

    public function provider(): ExchangeRateProvider
    {
        return $this->registry->resolve($this->settings->rateProvider());
    }

    /**
     * True when the configured provider needs a key and has not been given one.
     * Surfaced in settings so a silently non-functioning provider is visible.
     */
    public function isMisconfigured(): bool
    {
        return $this->provider()->requiresApiKey() && $this->apiKey() === null;
    }

    /**
     * The key to use: the environment's if set, otherwise the stored one.
     *
     * The precedence is the point. An operator who puts the key in their
     * environment has said they do not want it in the database, and no amount
     * of clicking in the settings page should override that.
     */
    private function apiKey(): ?string
    {
        if ($this->environmentApiKey !== '') {
            return $this->environmentApiKey;
        }

        $stored = $this->settings->storedRateProviderKey();

        return $stored === '' ? null : $stored;
    }

    private function mayAttemptNow(): bool
    {
        $lastAttempt = $this->settings->ratesLastAttemptAt();
        if ($lastAttempt === null) {
            return true;
        }

        return $lastAttempt->getTimestamp() + $this->retrySeconds <= $this->clock->now()->getTimestamp();
    }

    /**
     * @return array<string, ExchangeRate>
     */
    private function cachedRates(): array
    {
        return $this->cache ??= $this->repository->findAllForBase($this->settings->baseCurrency());
    }
}
