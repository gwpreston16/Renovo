<?php

declare(strict_types=1);

namespace App\Service;

use App\Security\Scope;

/**
 * The one place time-has-passed work happens, in the one order that is correct.
 *
 * Three things drift out of date on their own, with nobody editing anything:
 * a scheduled price change reaches its date, a free trial ends, and a payment
 * date falls into the past. Each is repaired lazily when somebody looks, and
 * the order they are repaired in is not arbitrary:
 *
 *   1. **Apply due price changes.** The subscription's current price must be
 *      right before anything reads it.
 *   2. **Convert ended trials.** A conversion sets a price and a first payment
 *      date, so it has to happen after step 1 could have moved that price and
 *      before step 3 rolls the date forward.
 *   3. **Advance overdue payment dates.** After the first two, because it
 *      consumes what they produce.
 *   4. **End finished fixed terms.** Last, so a term's final charge is
 *      already in the ledger when the row ends.
 *
 * Get this backwards and the errors are quiet ones. Advance payment dates
 * first and a renewal that just rolled over was priced at yesterday's figure,
 * so the twelve-month forecast is short by exactly the increase the user
 * scheduled — a number that looks entirely plausible and is wrong.
 *
 * Every step is a no-op when the scope cannot write. A GET that performs an
 * UPDATE because a Viewer happened to load the dashboard is not a write this
 * application makes; the work waits for somebody who can.
 *
 * This is also the seam the scheduler will call when reminders arrive. It needs
 * no stub today: the entry point is real, and a later phase invokes it per
 * household instead of per page view.
 */
final class CatchUpService
{
    public function __construct(
        private readonly PriceHistoryService $priceHistory,
        private readonly TrialService $trials,
        private readonly SubscriptionService $subscriptions,
        private readonly FixedTermService $terms,
    ) {
    }

    /**
     * @return array{prices: int, trials: int, payments: int, terms: int}
     */
    public function run(Scope $scope): array
    {
        if (!$scope->canWrite()) {
            return ['prices' => 0, 'trials' => 0, 'payments' => 0, 'terms' => 0];
        }

        return [
            'prices' => $this->priceHistory->applyDueChanges($scope),
            'trials' => $this->trials->convertDueTrials($scope),
            'payments' => $this->subscriptions->advanceDuePayments($scope),
            'terms' => $this->terms->endFinishedTerms($scope),
        ];
    }
}
