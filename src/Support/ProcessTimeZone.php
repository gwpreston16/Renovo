<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The zone PHP itself runs in: always UTC, whatever `date.timezone` says.
 *
 * The clock is UTC, and a date read from the database is parsed in PHP's
 * default zone. When the two differ, the same string is a different instant
 * from the clock's, and a date that is today reads as tomorrow or yesterday
 * (K68). Pinning the default removes that at its one cause, so no call site has
 * to name a zone.
 *
 * Every entry point calls {@see self::pin()} before it does anything else:
 * `config/bootstrap.php` (the web front controller and `bin/console`),
 * `phinx.php` and the test bootstrap. `EntryPointTimeZoneTest` fails if one is
 * added that does not.
 */
final class ProcessTimeZone
{
    public const ZONE = 'UTC';

    public static function pin(): void
    {
        date_default_timezone_set(self::ZONE);
    }
}
