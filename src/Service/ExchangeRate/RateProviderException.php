<?php

declare(strict_types=1);

namespace App\Service\ExchangeRate;

use RuntimeException;

/**
 * A provider could not supply rates.
 *
 * Always recoverable: the caller falls back to the cached table, and failing
 * that presents per-currency subtotals instead of a combined figure. A missing
 * rate degrades the display; it never blocks a page or invents a number.
 */
final class RateProviderException extends RuntimeException
{
    public static function transport(string $provider, string $reason, ?\Throwable $previous = null): self
    {
        return new self(sprintf('%s could not be reached: %s', $provider, $reason), 0, $previous);
    }

    public static function http(string $provider, int $status): self
    {
        return new self(sprintf('%s returned HTTP %d.', $provider, $status));
    }

    public static function malformed(string $provider, string $reason): self
    {
        return new self(sprintf('%s returned an unusable response: %s', $provider, $reason));
    }

    public static function missingApiKey(string $provider): self
    {
        return new self(sprintf('%s needs an API key, and none is configured.', $provider));
    }
}
