<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Why the environment rules update checks out, whatever the setting says.
 */
enum UpdateUnavailable: string
{
    case DisabledByEnvironment = 'environment';
    case InvalidRepository = 'repository';

    public function labelKey(): string
    {
        return 'updates.unavailable_' . $this->value;
    }
}
