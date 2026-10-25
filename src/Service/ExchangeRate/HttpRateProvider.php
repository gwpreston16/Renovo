<?php

declare(strict_types=1);

namespace App\Service\ExchangeRate;

use App\Domain\ExchangeRate;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Shared mechanics for the JSON-over-HTTPS providers: issue the request through
 * the application's one HTTP client, decode, and turn each published rate into
 * a scaled integer.
 *
 * The dependency is on ClientInterface rather than on the concrete HttpClient.
 * That is what the container binds, and it is what lets these classes be tested
 * against a recorded response with no network involved.
 */
abstract class HttpRateProvider implements ExchangeRateProvider
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws RateProviderException
     */
    protected function getJson(string $url): array
    {
        $request = $this->requests->createRequest('GET', $url)
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw RateProviderException::transport($this->label(), $exception->getMessage(), $exception);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw RateProviderException::http($this->label(), $status);
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw RateProviderException::malformed($this->label(), $exception->getMessage());
        }

        if (!is_array($decoded)) {
            throw RateProviderException::malformed($this->label(), 'the body was not a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Turn a published rate into a scaled integer.
     *
     * json_decode hands back a float for any unquoted JSON number, so the value
     * is printed in fixed notation before being parsed as a decimal string.
     * That printing step is the only place a float touches the money path, and
     * it is safe in a way worth being explicit about: twelve decimal places is
     * far inside a double's ~15–17 significant digits for any rate a provider
     * publishes, so the digits recovered are the digits sent. Everything after
     * this line is integer arithmetic.
     *
     * @return int|null Null when the entry is not a usable number, which is how
     *                  a provider reports a currency it has no data for.
     */
    protected function scaledRate(mixed $value): ?int
    {
        $decimal = match (true) {
            is_int($value) => (string) $value,
            is_string($value) => trim($value),
            is_float($value) => is_finite($value) ? sprintf('%.12F', $value) : '',
            default => '',
        };

        if ($decimal === '') {
            return null;
        }

        try {
            return ExchangeRate::scaleFromDecimalString($decimal);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    protected function scaleAll(array $payload): array
    {
        $rates = [];
        foreach ($payload as $code => $value) {
            $scaled = $this->scaledRate($value);
            if ($scaled !== null) {
                $rates[(string) $code] = $scaled;
            }
        }

        return $rates;
    }

    protected function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $date === false ? null : $date;
    }

    /**
     * @throws RateProviderException
     */
    protected function requireApiKey(?string $apiKey): string
    {
        $apiKey = trim($apiKey ?? '');
        if ($apiKey === '') {
            throw RateProviderException::missingApiKey($this->label());
        }

        return $apiKey;
    }
}
