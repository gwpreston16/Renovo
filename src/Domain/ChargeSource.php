<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How a ledger row came to exist.
 *
 * Kept so the Payments list can say which rows are Renovo's own record and
 * which were rebuilt after the fact, and so `ledger:backfill` can tell its own
 * rows from ones it must never overwrite.
 */
enum ChargeSource: string
{
    /** Written by the catch-up as the billing date passed. */
    case CatchUp = 'catch_up';

    /** Added by a member, for a charge that fell off the cycle. */
    case Manual = 'manual';

    /** Rebuilt by `ledger:backfill` for a date before the ledger existed. */
    case Reconstructed = 'reconstructed';

    /**
     * A one-off or lifetime entry's own single charge, on its date — written by
     * the catch-up or the backfill. Its own source because it is the one row
     * a change of the entry's date moves: charges a row recorded while it was
     * recurring carry `catch_up` or `reconstructed` and are left alone.
     */
    case OneOff = 'one_off';

    public function labelKey(): string
    {
        return 'charge.source.' . $this->value;
    }
}
