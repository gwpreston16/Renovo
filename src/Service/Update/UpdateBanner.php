<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * The dashboard's "a newer release is out" banner, for one instance
 * administrator.
 */
final class UpdateBanner
{
    public function __construct(
        public readonly string $latestVersion,
        public readonly string $currentVersion,
        public readonly ?string $releaseUrl,
    ) {
    }
}
