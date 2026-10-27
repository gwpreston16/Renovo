<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * A `Y-m-d` date as a form or an API client sends it.
 *
 * `DateTimeImmutable::createFromFormat()` reads "2026-02-30" as 2 March and
 * only notes it in a warning, so a date that does not exist would be stored as
 * another one (K39). Here it is no date, and every caller reports it against
 * its own field.
 */
final class IsoDate
{
    public static function parse(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
