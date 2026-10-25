<?php

declare(strict_types=1);

namespace App\Http;

use Throwable;

/**
 * Telling a refusal by the instance's outbound mode from any other failure
 * (Phase 43), through however many layers wrapped it on the way up.
 *
 * A rate provider, a notifier and the update check each turn the client's
 * exception into their own. They keep the original as `previous`, and the
 * screens ask here for the reason so they can say "turned off" or "not on the
 * allowed list" in the reader's language instead of a generic failure.
 */
final class OutboundRefusal
{
    /**
     * @return string|null `outbound_off`, `outbound_not_allowlisted`, or null
     *         when the failure is not the outbound mode's.
     */
    public static function reasonOf(Throwable $failure): ?string
    {
        for ($e = $failure; $e !== null; $e = $e->getPrevious()) {
            if (
                $e instanceof BlockedTargetException
                && in_array($e->reason, [
                    BlockedTargetException::OUTBOUND_OFF,
                    BlockedTargetException::OUTBOUND_NOT_ALLOWLISTED,
                ], true)
            ) {
                return $e->reason;
            }
        }

        return null;
    }

    /**
     * The translation key for a reason `reasonOf()` returned.
     */
    public static function messageKey(string $reason): string
    {
        return $reason === BlockedTargetException::OUTBOUND_OFF
            ? 'flash.outbound_off'
            : 'flash.outbound_not_allowlisted';
    }
}
