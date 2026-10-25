<?php

declare(strict_types=1);

namespace App\Service\ExchangeRate;

use App\Domain\Currency;

/**
 * exchangerate.host — optional, key required.
 *
 * Its current API keys every quote as the concatenated pair ("GBPEUR"), so the
 * source code is stripped from each key to recover the currency. A key that
 * does not start with the source is discarded rather than guessed at.
 */
final class ExchangeRateHostProvider extends HttpRateProvider
{
    public const KEY = 'exchangerate_host';

    private const ENDPOINT = 'https://api.exchangerate.host/live';

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
        return 'exchangerate.host';
    }

    public function descriptionKey(): string
    {
        return 'rate_provider.exchangerate_host';
    }

    public function requiresApiKey(): bool
    {
        return true;
    }

    public function fetch(string $preferredBase, ?string $apiKey): RateTable
    {
        $base = Currency::normalise($preferredBase);

        $payload = $this->getJson(sprintf(
            '%s?access_key=%s&source=%s',
            self::ENDPOINT,
            urlencode($this->requireApiKey($apiKey)),
            urlencode($base),
        ));

        $this->assertSuccess($payload);

        $quotes = $payload['quotes'] ?? null;
        if (!is_array($quotes) || $quotes === []) {
            throw RateProviderException::malformed($this->label(), 'no quotes were present');
        }

        $source = is_string($payload['source'] ?? null) ? Currency::normalise($payload['source']) : $base;

        $rates = [];
        foreach ($quotes as $pair => $value) {
            $pair = (string) $pair;
            if (!str_starts_with($pair, $source) || strlen($pair) !== strlen($source) + 3) {
                continue;
            }
            $rates[substr($pair, strlen($source))] = $value;
        }

        return RateTable::of(
            $source,
            $this->scaleAll($rates),
            $this->parseDate(is_string($payload['date'] ?? null) ? $payload['date'] : null),
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
