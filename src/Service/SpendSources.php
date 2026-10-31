<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Charge;
use App\Domain\Entity\PriceChange;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionSplit;
use App\Security\Scope;
use DateTimeImmutable;

/**
 * What the past-spend walks read, loaded once for a scope and a window.
 *
 * Every walk in `SpendHistoryService` answers a question about charges in a
 * window from the same four things: the household's subscriptions, each one's
 * price history, the ledger rows in the window, and where each ledger begins.
 * A screen that makes several walks used to load all four for each of them; it
 * now loads them once and hands this to every walk (K155, K167).
 *
 * Explicit rather than cached inside the service: the object belongs to the
 * request that made it, so nothing can read it after the catch-up has written
 * a row it does not hold. A walk handed sources that do not cover its scope
 * and window loads its own, so a wrong hand-off costs time and never a figure.
 *
 * @phpstan-type FirstDueDates array<int, array{written: DateTimeImmutable|null, any: DateTimeImmutable}>
 */
final class SpendSources
{
    /** @var array<int, list<SubscriptionSplit>>|null */
    private ?array $splits = null;

    /**
     * @param list<Subscription> $subscriptions
     * @param array<int, list<PriceChange>> $history
     * @param array<int, list<Charge>> $ledger
     * @param FirstDueDates $first
     */
    public function __construct(
        public readonly Scope $scope,
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly array $subscriptions,
        public readonly array $history,
        public readonly array $ledger,
        public readonly array $first,
    ) {
    }

    /**
     * Whether a walk of `($from, $to]` in `$scope` can be answered from this.
     */
    public function covers(Scope $scope, DateTimeImmutable $from, DateTimeImmutable $to): bool
    {
        return $this->scope == $scope && $from >= $this->from && $to <= $this->to;
    }

    /**
     * The household's splits, read on first use: only a walk for one member
     * needs them.
     *
     * @param callable(): array<int, list<SubscriptionSplit>> $load
     * @return array<int, list<SubscriptionSplit>>
     */
    public function splits(callable $load): array
    {
        return $this->splits ??= $load();
    }
}
