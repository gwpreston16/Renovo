<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What one tag and one subscription's tags may hold, in the one place the
 * subscription form, the bulk "Add tag" action and a backup restore all read.
 */
final class TagLimits
{
    public const MAX_PER_SUBSCRIPTION = 25;
    public const MAX_NAME_LENGTH = 50;

    public static function nameFits(string $name): bool
    {
        return mb_strlen($name) <= self::MAX_NAME_LENGTH;
    }
}
