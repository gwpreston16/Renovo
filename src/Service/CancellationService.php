<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * The cancel-by dashboard.
 *
 * The date that actually matters to somebody trying to get out of a
 * subscription is not the renewal date — it is the last day they can still give
 * notice and avoid the next charge. For anything with a notice period those are
 * different dates, and the second one is the one that is easy to miss.
 *
 * So this view is sorted by urgency: deadlines already gone, then deadlines
 * this week, then the rest. Something whose deadline has passed is not dropped
 * from the list; it is shown first, because the user has just been committed to
 * another period and should know.
 *
 * A contract's end date sits beside the deadlines, sorted by the same rule:
 * it is the other date somebody leaving or renegotiating has to catch. One
 * that has passed stays, first, until the date is moved — the row is out of
 * contract, which is when to check.
 */
final class CancellationService
{
    /** A deadline inside this many days is shown as urgent. */
    public const URGENT_DAYS = 14;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Every active subscription with a cancellation deadline, most urgent
     * first.
     *
     * `kind` is `notice` for a cancellation deadline and `contract` for a
     * contract's end date.
     *
     * @return list<array{
     *     subscription: Subscription,
     *     kind: 'notice'|'contract',
     *     deadline: DateTimeImmutable,
     *     days_remaining: int,
     *     is_passed: bool,
     *     is_urgent: bool
     * }>
     */
    public function deadlines(Scope $scope, ?int $withinDays = null): array
    {
        $today = $this->clock->today();
        $rows = [];

        // Only the rows that can have a deadline are read (K168); the checks
        // below still say what makes one.
        foreach ($this->subscriptions->withDeadlines($scope) as $subscription) {
            if (!$subscription->isActive || !$subscription->type->countsTowardsRecurringTotals()) {
                continue;
            }

            if (!$subscription->hasChargesRemaining($today)) {
                // A fixed term past its last payment: nothing left to stop.
                continue;
            }

            $dates = [];

            // No notice period: the deadline is simply the renewal date, which
            // the upcoming-renewals view already covers. Listing it here as
            // well would bury the entries that need the warning.
            $deadline = $subscription->cancellationDeadline();
            if ($deadline !== null) {
                $dates['notice'] = $deadline;
            }
            if ($subscription->contractEndsOn !== null) {
                $dates['contract'] = $subscription->contractEndsOn;
            }

            foreach ($dates as $kind => $date) {
                $days = (int) $today->diff($date->setTime(0, 0))->format('%r%a');

                if ($withinDays !== null && $days > $withinDays) {
                    continue;
                }

                $rows[] = [
                    'subscription' => $subscription,
                    'kind' => $kind,
                    'deadline' => $date,
                    'days_remaining' => $days,
                    'is_passed' => $days < 0,
                    'is_urgent' => $days >= 0 && $days <= self::URGENT_DAYS,
                ];
            }
        }

        // Soonest first, so anything already missed leads — that is the row the
        // user most needs to see, even though nothing can be done about it.
        usort(
            $rows,
            static fn (array $a, array $b): int => $a['days_remaining'] <=> $b['days_remaining']
                ?: strcmp($a['subscription']->name, $b['subscription']->name),
        );

        return $rows;
    }

    /**
     * How many deadlines fall inside the urgent window, for the dashboard
     * badge.
     */
    public function urgentCount(Scope $scope): int
    {
        $count = 0;
        foreach ($this->deadlines($scope) as $row) {
            if ($row['is_urgent']) {
                $count++;
            }
        }

        return $count;
    }
}
