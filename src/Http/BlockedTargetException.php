<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A destination the guard refused.
 *
 * The messages are written to be shown to the person who typed the URL: they
 * say what was wrong and, where the allowlist is the answer, that an
 * administrator can permit it. They never include a resolved address, which
 * would turn a refusal into a way of reading the internal network one guess at
 * a time.
 *
 * `$reason` is the machine-readable cause (Phase 43): the audit entry records
 * it and a screen picks its translated wording from it, so neither parses the
 * message.
 */
final class BlockedTargetException extends RuntimeException implements ClientExceptionInterface
{
    public const OUTBOUND_OFF = 'outbound_off';
    public const OUTBOUND_NOT_ALLOWLISTED = 'outbound_not_allowlisted';
    public const PRIVATE_ADDRESS = 'private_address';
    public const REDIRECT = 'redirect';
    public const SCHEME = 'scheme';
    public const MALFORMED = 'malformed';
    public const UNRESOLVABLE = 'unresolvable';

    private bool $recorded = false;

    public function __construct(string $message, public readonly string $reason = self::MALFORMED)
    {
        parent::__construct($message);
    }

    public static function scheme(string $scheme): self
    {
        return new self(sprintf(
            'The URL uses "%s". Only https is allowed, or http for a host an administrator has trusted.',
            $scheme === '' ? 'no scheme' : $scheme,
        ), self::SCHEME);
    }

    public static function malformed(string $reason): self
    {
        return new self('That URL cannot be used: ' . $reason . '.', self::MALFORMED);
    }

    public static function unresolvable(string $host): self
    {
        return new self(sprintf('The host "%s" does not resolve.', $host), self::UNRESOLVABLE);
    }

    public static function privateAddress(string $host): self
    {
        return new self(sprintf(
            'The host "%s" resolves to a private or reserved address. An administrator can allow it '
            . 'by adding it to the trusted-host list.',
            $host,
        ), self::PRIVATE_ADDRESS);
    }

    public static function redirect(string $reason): self
    {
        return new self(
            'The request was redirected somewhere it is not allowed to go: ' . $reason . '.',
            self::REDIRECT,
        );
    }

    public static function tooManyRedirects(int $limit): self
    {
        return new self(sprintf('The request was redirected more than %d times.', $limit), self::REDIRECT);
    }

    /**
     * The instance's outbound mode refused the host (Phase 43).
     */
    public static function outbound(string $reason, string $host): self
    {
        return new self(
            $reason === self::OUTBOUND_OFF
                ? 'Outbound requests are turned off on this instance.'
                : sprintf('The host "%s" is not on this instance\'s allowed list for outbound requests.', $host),
            $reason,
        );
    }

    /**
     * Whether the refusal has already been written to the audit log, so the
     * layer that catches it does not write it a second time.
     */
    public function markRecorded(): self
    {
        $this->recorded = true;

        return $this;
    }

    public function isRecorded(): bool
    {
        return $this->recorded;
    }
}
