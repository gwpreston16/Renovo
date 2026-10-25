<?php

declare(strict_types=1);

namespace App\Service\ExchangeRate;

use App\Domain\Currency;

/**
 * Frankfurter — the default.
 *
 * It is free, needs no account or key, serves any of its currencies as the
 * base, and publishes the European Central Bank's daily reference rates. That
 * combination is why it is the default: a new instance converts currencies
 * correctly without the operator signing up for anything.
 *
 * The ECB set covers the major currencies and no more. A currency outside it
 * simply has no rate, and the application shows that currency as its own
 * subtotal rather than guessing.
 */
final class FrankfurterProvider extends HttpRateProvider
{
    public const KEY = 'frankfurter';

    private const ENDPOINT = 'https://api.frankfurter.app/latest';

    public function outboundHost(): string
    {
        return (string) parse_url(self::ENDPOINT, PHP_URL_HOST);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Frankfurter';
    }

    public function descriptionKey(): string
    {
        return 'rate_provider.frankfurter';
    }

    public function requiresApiKey(): bool
    {
        return false;
    }

    public function fetch(string $preferredBase, ?string $apiKey): RateTable
    {
        $base = Currency::normalise($preferredBase);

        $payload = $this->getJson(self::ENDPOINT . '?from=' . urlencode($base));

        $rates = $payload['rates'] ?? null;
        if (!is_array($rates) || $rates === []) {
            throw RateProviderException::malformed($this->label(), 'no rates were present');
        }

        /** @var array<string, mixed> $rates */
        return RateTable::of(
            // Trust the base the provider says it used, not the one asked for:
            // an unsupported base comes back as the feed's own.
            is_string($payload['base'] ?? null) ? $payload['base'] : $base,
            $this->scaleAll($rates),
            $this->parseDate($payload['date'] ?? null),
            self::KEY,
        );
    }
}
