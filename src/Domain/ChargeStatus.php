<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Whether a charge in the ledger happened.
 *
 * Two states, not three: a partial refund is a charge whose actual amount is
 * smaller, recorded as an actual, not a status of its own.
 */
enum ChargeStatus: string
{
    /** The charge was taken — the default for every billing date that passes. */
    case Charged = 'charged';

    /** It did not happen: waived, missed, or refunded in full. Counts as nothing. */
    case Skipped = 'skipped';

    public function labelKey(): string
    {
        return 'charge.status.' . $this->value;
    }
}
