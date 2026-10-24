<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The Content-Security-Policy nonce for the response being built.
 *
 * One per response, never reused: SecurityHeadersMiddleware calls `renew()` as
 * each request arrives, and the same value is then both written into the
 * policy header and handed to templates as `csp_nonce()`. A long-lived process
 * (the test suite, a worker) handles many requests with one container, which is
 * why the value is renewed rather than generated once at construction.
 */
final class CspNonce
{
    private ?string $value = null;

    public function renew(): string
    {
        // 16 bytes, base64: the CSP spec asks for at least 128 bits, and the
        // base64 alphabet is what a nonce-source is defined over.
        $this->value = base64_encode(random_bytes(16));

        return $this->value;
    }

    public function value(): string
    {
        return $this->value ?? $this->renew();
    }
}
