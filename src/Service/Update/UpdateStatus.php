<?php

declare(strict_types=1);

namespace App\Service\Update;

use DateTimeImmutable;

/**
 * The update check as the Instance status card and the API draw it.
 *
 * `releaseUrl` is the stored release page only when it is on
 * `https://github.com/`: it came from a third party's response, and a link is
 * drawn to nothing else.
 */
final class UpdateStatus
{
    public function __construct(
        public readonly UpdateState $state,
        public readonly string $currentVersion,
        public readonly ?string $latestVersion,
        public readonly ?string $releaseUrl,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly ?DateTimeImmutable $checkedAt,
        public readonly ?DateTimeImmutable $lastAttemptAt,
        public readonly ?UpdateUnavailable $unavailable,
    ) {
    }
}
