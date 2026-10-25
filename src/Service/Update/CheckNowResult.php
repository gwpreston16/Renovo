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
    /** Refused by the instance: `OUTBOUND_HTTP=off` (Phase 43). */
    case OutboundOff = 'outbound_off';
    /** Refused by the instance: GitHub is not on the allowed list (Phase 43). */
    case OutboundNotAllowed = 'outbound_not_allowed';
}
