<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * What the Check now button did.
 */
enum CheckNowResult: string
{
    case Off = 'off';
    /** Refused: the last attempt was less than a minute ago. */
    case TooSoon = 'too_soon';
    case Checked = 'checked';
    case Failed = 'failed';
}
