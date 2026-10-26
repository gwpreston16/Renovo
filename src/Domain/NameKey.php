<?php

declare(strict_types=1);

namespace App\Domain;

use Normalizer;

/**
 * What two names must share to count as the same one.
 *
 * Category, tag and payment-method names are unique per household, and the
 * MySQL unique indexes (`utf8mb4_unicode_ci`) already treat "Café", "cafe" and
 * "CAFÉ" as one name. PostgreSQL's would not, so the check is made here, on
 * both engines, by comparing this key: accents dropped, case folded.
 */
final class NameKey
{
    public static function of(string $name): string
    {
        $decomposed = Normalizer::normalize(trim($name), Normalizer::FORM_D);
        $stripped = preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $name : $decomposed);

        return mb_strtolower($stripped ?? $name);
    }

    public static function same(string $a, string $b): bool
    {
        return self::of($a) === self::of($b);
    }
}
