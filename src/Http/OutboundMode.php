<?php

declare(strict_types=1);

namespace App\Http;

/**
 * What this instance lets its HTTP client reach (`OUTBOUND_HTTP`, Phase 43).
 *
 * `Open` is the behaviour before the setting existed: any public host, and a
 * private one only when an administrator trusted it. `Allowlist` narrows that
 * to hosts the administrator listed and the fixed endpoints of the features
 * that are switched on. `Off` sends nothing at all.
 *
 * SMTP is not HTTP and is not governed by this.
 */
enum OutboundMode: string
{
    case Open = 'open';
    case Allowlist = 'allowlist';
    case Off = 'off';

    /**
     * Read the variable. A value that is not one of the three is `Off`, not
     * `Open`: an operator who typed "allow-list" or "disabled" meant to
     * restrict the instance, and a typo must not leave it unrestricted.
     */
    public static function fromSetting(?string $value): self
    {
        $value = strtolower(trim($value ?? ''));

        if ($value === '') {
            return self::Open;
        }

        return self::tryFrom($value) ?? self::Off;
    }

    public function restricts(): bool
    {
        return $this !== self::Open;
    }
}
