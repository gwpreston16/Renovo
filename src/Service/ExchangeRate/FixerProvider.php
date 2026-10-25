<?php

declare(strict_types=1);

namespace App\Service\ExchangeRate;

/**
 * Fixer — optional, key required, and deliberately never the default.
 *
 * Its free tier publishes EUR as the base and nothing else, so this provider
 * asks for EUR regardless of what the instance's base currency is and lets
 * RateTable::rebasedTo() derive the rest. Sending `base=GBP` on a free key
 * returns an error rather than GBP rates, and treating that as a transport
 * failure would leave an instance mysteriously without rates.
 *
 * It is offered because some operators already pay for it. It is not the
 * default because requiring an account to convert two currencies is a poor
 * first-run experience, and because a self-hosted application should work with
 * no third-party relationship at all.
 */
final class FixerProvider extends HttpRateProvider
{
    public const KEY = 'fixer';

    private const ENDPOINT = 'https://data.fixer.io/api/latest';

    /** The only base the free tier serves. */
    private const FREE_TIER_BASE = 'EUR';

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
        return 'Fixer';
    }

    public function descriptionKey(): string
    {
        return 'rate_provider.fixer';
    }

    public function requiresApiKey(): bool
    {
        return true;
    }

    public function fetch(string $preferredBase, ?string $apiKey): RateTable
    {
        $payload = $this->getJson(sprintf(
            '%s?access_key=%s&base=%s',
            self::ENDPOINT,
            urlencode($this->requireApiKey($apiKey)),
            self::FREE_TIER_BASE,
        ));

        $this->assertSuccess($payload);

        $rates = $payload['rates'] ?? null;
        if (!is_array($rates) || $rates === []) {
            throw RateProviderException::malformed($this->label(), 'no rates were present');
        }

        /** @var array<string, mixed> $rates */
        return RateTable::of(
            is_string($payload['base'] ?? null) ? $payload['base'] : self::FREE_TIER_BASE,
            $this->scaleAll($rates),
            $this->parseDate($payload['date'] ?? null),
            self::KEY,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @throws RateProviderException
     */
    private function assertSuccess(array $payload): void
    {
        if (($payload['success'] ?? true) !== false) {
            return;
        }

        $error = $payload['error'] ?? [];
        $info = is_array($error) && is_string($error['info'] ?? null) ? $error['info'] : 'the request was rejected';

        throw RateProviderException::malformed($this->label(), $info);
    }
}
