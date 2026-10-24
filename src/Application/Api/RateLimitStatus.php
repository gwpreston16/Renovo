<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Service\RateLimitDecision;

/**
 * Where the current API request stands against its rate limit, held for the
 * one middleware that writes the headers.
 *
 * The limit is decided inside the token middleware, but most API responses
 * — every 401, 403, 404, 422 and the 429 itself — are built by the error
 * handler from an exception, which carries no headers. So the decision is
 * left here, and `RateLimitHeadersMiddleware`, outside the error handler,
 * adds it to whatever response comes back. Like `RequestContextHolder`, it
 * is reset at the start of every request.
 */
final class RateLimitStatus
{
    private ?RateLimitDecision $decision = null;

    public function set(RateLimitDecision $decision): void
    {
        $this->decision = $decision;
    }

    public function get(): ?RateLimitDecision
    {
        return $this->decision;
    }

    public function clear(): void
    {
        $this->decision = null;
    }
}
