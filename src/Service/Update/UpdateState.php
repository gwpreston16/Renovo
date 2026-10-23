<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Where the update check stands, as the Instance status card says it.
 */
enum UpdateState: string
{
    /** Turned off in settings, or ruled out by the environment. */
    case Off = 'off';
    /** On, and no check has finished yet. */
    case NeverChecked = 'never_checked';
    /** The repository has published no release. */
    case NoReleases = 'no_releases';
    case UpToDate = 'up_to_date';
    case Available = 'available';
    /** The last attempt failed; the last good result, if any, is kept. */
    case Failed = 'failed';
}
