<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * An amount past `Money::MAX_MINOR`. A kind of "not a valid amount", so a
 * caller that only distinguishes valid from invalid needs no change; one that
 * wants to say "too large" rather than "not a number" catches this first.
 */
final class AmountTooLargeException extends InvalidArgumentException
{
}
