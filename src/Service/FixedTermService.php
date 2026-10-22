<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\ChargeSource;
use App\Domain\Entity\Charge;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Persistence\Database;
use App\Repository\ChargeRepository;
use App\Repository\SubscriptionRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Fixed terms — a phone, a sofa, a car on finance: a set number of payments,
 * then nothing.
 *
 * Two things live here. The catch-up's last step ends a term whose final
 * payment has passed, and the subscription's money page shows how far along
 * one is. Neither stores a balance or an interest rate: a fixed term is a
 * count of payments, not a loan calculator.
 *
 * A finished term is a cancellation, not a status of its own, so every
 * total, filter and report already treats it as one. The audit entry is what
 * says why it was cancelled.
 */
final class FixedTermService
{
    /** The catch-up's loop guard, for a schedule centuries long. */
    private const MAX_WALK = 15_000;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly ChargeRepository $chargeRows,
        private readonly ChargeService $charges,
        private readonly AuditLogService $audit,
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * End every fixed term this scope may write whose last payment has
     * passed: cancelled as of the day after that payment, with an audit entry.
     *
     * Run after the payment dates have been advanced, so the final charge is
     * already in the ledger when the row ends. A paused term is ended all the
     * same; the dates its pause stepped over, up to the last payment, are
     * recorded as skipped first, as a resume would record them.
     *
     * @return int How many terms this call ended.
     */
    public function endFinishedTerms(Scope $scope): int
    {
        if (!$scope->canWrite()) {
            return 0;
        }

        $today = $this->clock->today();
        $ended = 0;

        foreach ($this->subscriptions->findOpenFixedTerms($scope) as $subscription) {
            if ($subscription->hasChargesRemaining($today)) {
                continue;
            }

            if ($this->end($scope, $subscription, $today)) {
                $ended++;
            }
        }

        return $ended;
    }

    /**
     * How far along a fixed term is: "Payment 14 of 36", "£1,078.00 of
     * £2,772.00 paid". Null for a row with no fixed term, or one whose
     * schedule cannot be walked (no start date, and no date to start from).
     *
     * The count of payments is the billing dates from the start to the last
     * payment. A date that has passed is paid; where the ledger has a row for
     * it, its amount is what the ledger says was paid (nothing, for a skipped
     * one), and otherwise the price. Dates still to come are counted at the
     * current price.
     *
     * `width` is the paid share of the payments, for the Payments card's
     * track.
     *
     * @return array{paid: int, total: int, paid_amount: Money, total_amount: Money, width: string}|null
     */
    public function progress(Scope $scope, Subscription $subscription): ?array
    {
        $end = $subscription->paymentsEndOn;
        $cycle = $subscription->billingCycle;
        $start = $subscription->startDate ?? $subscription->nextChargeDate();
        if (
            $end === null
            || $cycle === null
            || $start === null
            || !$subscription->type->countsTowardsRecurringTotals()
        ) {
            return null;
        }

        $currency = $subscription->price->currency;
        $ledger = [];
        foreach ($this->chargeRows->findForSubscription($scope, $subscription->id) as $charge) {
            if ($charge->amount()->currency === $currency) {
                $ledger[$charge->dueDate->format('Y-m-d')] = $charge;
            }
        }

        $today = $this->clock->today();
        $next = $subscription->isCancelled() ? null : $subscription->nextChargeDate();
        $paid = 0;
        $total = 0;
        $paidMinor = 0;
        $totalMinor = 0;

        $date = $start;
        for ($i = 0; $date <= $end && $i < self::MAX_WALK; $i++) {
            $total++;
            $charge = $ledger[$date->format('Y-m-d')] ?? null;
            $passed = $next !== null ? $date < $next : $date < $today;

            if ($passed) {
                $paid++;
                $amount = $charge !== null ? $this->paidMinor($charge) : $subscription->price->amountMinor;
                $paidMinor += $amount;
                $totalMinor += $amount;
            } else {
                $totalMinor += $subscription->price->amountMinor;
            }

            $date = $this->advance($subscription, $date);
        }

        if ($total === 0) {
            return null;
        }

        return [
            'paid' => $paid,
            'total' => $total,
            'paid_amount' => Money::of($paidMinor, $currency),
            'total_amount' => Money::of($totalMinor, $currency),
            'width' => Rounding::divide($paid * 1000, $total) / 10 . '%',
        ];
    }

    private function end(Scope $scope, Subscription $subscription, DateTimeImmutable $today): bool
    {
        return $this->db->transactional(function () use ($scope, $subscription, $today): bool {
            $skipped = [];
            if (!$subscription->isActive) {
                $skipped = array_values(array_filter(
                    $subscription->pausedDates($today),
                    static fn (DateTimeImmutable $date): bool => $subscription->chargesOn($date),
                ));
                $this->charges->recordPaused($scope, $subscription, $skipped);
            }

            $last = $this->lastPayment($scope, $subscription, $subscription->paymentsEndOn ?? $today);
            $endedOn = $last->modify('+1 day');

            if (!$this->subscriptions->endTerm($scope, $subscription->id, $endedOn)) {
                return false;
            }

            $this->audit->record(
                AuditAction::SubscriptionEnded,
                null,
                [
                    'subscription_id' => $subscription->id,
                    'last_payment' => $last->format('Y-m-d'),
                ],
                $subscription->householdId,
                $subscription->ownerUserId,
                $subscription->name,
            );

            return true;
        });
    }

    /**
     * The date of a term's final payment: the latest billing date in the
     * ledger on or before its end, which the catch-up has just written. A row
     * the ledger has nothing for is walked from its start; failing both, the
     * end date itself.
     */
    private function lastPayment(Scope $scope, Subscription $subscription, DateTimeImmutable $end): DateTimeImmutable
    {
        $billing = [ChargeSource::CatchUp, ChargeSource::Reconstructed, ChargeSource::Paused];

        $last = null;
        foreach ($this->chargeRows->existingDates($scope, $subscription->id) as $date => $source) {
            $date = (string) $date;
            if (!in_array($source, $billing, true) || $date > $end->format('Y-m-d')) {
                continue;
            }
            if ($last === null || $date > $last) {
                $last = $date;
            }
        }
        if ($last !== null) {
            return new DateTimeImmutable($last);
        }

        $date = $subscription->billingCycle === null ? null : $subscription->startDate;
        $walked = null;
        for ($i = 0; $date !== null && $date <= $end && $i < self::MAX_WALK; $i++) {
            $walked = $date;
            $date = $this->advance($subscription, $date);
        }

        return $walked ?? $end;
    }

    private function advance(Subscription $subscription, DateTimeImmutable $date): DateTimeImmutable
    {
        return $subscription->billingCycle?->advance(
            $date,
            $subscription->cycleDays,
            $subscription->anchorDay,
            $subscription->cycleInterval,
        ) ?? $date->modify('+1 day');
    }

    private function paidMinor(Charge $charge): int
    {
        return $charge->isSkipped() ? 0 : $charge->amount()->amountMinor;
    }
}
