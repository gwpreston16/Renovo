<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\BillingCycle;
use App\Domain\Money;
use App\Domain\NoticePeriod;
use App\Domain\Rounding;
use App\Domain\SplitMode;
use App\Domain\SubscriptionStatus;
use App\Domain\SubscriptionType;
use App\Domain\Visibility;
use DateTimeImmutable;

/**
 * One tracked subscription or recurring bill.
 *
 * `ownerUserId` is the member the cost belongs to and the column the isolation
 * rules key on. `payerUserId` is who actually pays it, which may be somebody
 * else in the household and carries no visibility meaning.
 */
final class Subscription
{
    /**
     * @param list<Tag> $tags
     */
    public function __construct(
        public readonly int $id,
        public readonly int $householdId,
        public readonly int $ownerUserId,
        public readonly ?int $payerUserId,
        public readonly string $name,
        public readonly ?string $notes,
        public readonly Money $price,
        public readonly SubscriptionType $type,
        public readonly ?BillingCycle $billingCycle,
        public readonly ?int $cycleDays,
        public readonly ?DateTimeImmutable $nextPaymentDate,
        public readonly ?DateTimeImmutable $startDate,
        public readonly ?int $anchorDay,
        public readonly NoticePeriod $noticePeriod,
        public readonly bool $isTrial,
        public readonly ?DateTimeImmutable $trialEndDate,
        public readonly ?Money $convertsToPrice,
        public readonly ?BillingCycle $convertsToBillingCycle,
        public readonly ?int $convertsToCycleDays,
        public readonly SplitMode $splitMode,
        public readonly int $usageCount,
        public readonly ?int $usageRating,
        public readonly ?DateTimeImmutable $usageCountedSince,
        public readonly bool $isActive,
        public readonly ?string $reminderDays,
        public readonly ?string $logoPath,
        public readonly ?string $websiteUrl,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        public readonly ?string $ownerName,
        public readonly ?string $payerName,
        public readonly array $tags,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        /**
         * Whether the owner has a picture, so the list can draw a face without
         * a query per row. Trailing with a default because it is an addition
         * to an entity that predates it, like the display preferences on User.
         */
        public readonly bool $ownerHasAvatar = false,
        /**
         * What it is paid with, joined for display as the category is. The
         * icon and logo are the method's, carried here so the list can draw
         * a badge without a query per row.
         */
        public readonly ?int $paymentMethodId = null,
        public readonly ?string $paymentMethodName = null,
        public readonly ?string $paymentMethodIcon = null,
        public readonly ?string $paymentMethodLogoPath = null,
        public readonly ?string $paymentMethodColour = null,
        /**
         * Who may see it. `Payer` keeps it to its owner; the scoping layer is
         * what enforces that, so a row that reached this entity is one the
         * reader was allowed to see.
         */
        public readonly Visibility $visibility = Visibility::Household,
        /**
         * The day it was cancelled. Set only together with `isActive = false`
         * — SubscriptionService holds the two in step — so a cancelled row is
         * excluded from everything a paused one is.
         */
        public readonly ?DateTimeImmutable $cancelledAt = null,
        /** The tier it is on — "Standard", "Family" — as the provider names it. */
        public readonly ?string $plan = null,
        /**
         * The category's colour, joined with its name, so a breakdown can draw
         * each category's bar in it without a query per category.
         */
        public readonly ?string $categoryColour = null,
        /**
         * Every N weeks, months or years — a six-monthly policy is monthly
         * with 6. Always 1 for quarterly and custom-day cycles.
         */
        public readonly int $cycleInterval = 1,
        /** The interval of the cycle a trial converts to; null for its own. */
        public readonly ?int $convertsToCycleInterval = null,
        /**
         * The day its contract ends. The row keeps running after it; the
         * date marks when the terms lapse and a dearer rate usually begins.
         */
        public readonly ?DateTimeImmutable $contractEndsOn = null,
        /**
         * The last charge of a fixed term: the last billing date on or before
         * it is the final payment, and the catch-up ends the row the day after.
         */
        public readonly ?DateTimeImmutable $paymentsEndOn = null,
        /** Whether the amount varies from bill to bill, making `price` an estimate. */
        public readonly bool $amountVaries = false,
    ) {
    }

    /**
     * The state the interface names, derived here and nowhere else.
     *
     * The order is the precedence: a cancelled trial is Cancelled, a paused
     * trial is Paused, and only a running row is a Trial or Active.
     */
    public function status(): SubscriptionStatus
    {
        return match (true) {
            $this->cancelledAt !== null => SubscriptionStatus::Cancelled,
            !$this->isActive => SubscriptionStatus::Paused,
            $this->isTrial => SubscriptionStatus::Trial,
            default => SubscriptionStatus::Active,
        };
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }

    /**
     * Whether a billing date falls inside a fixed term — always, for a row
     * without one. Every reader that walks dates forward asks this, so a row
     * past its last charge stops counting whether or not the catch-up has
     * ended it yet (a Viewer never runs the catch-up).
     */
    public function chargesOn(DateTimeImmutable $date): bool
    {
        return $this->paymentsEndOn === null || $date->setTime(0, 0) <= $this->paymentsEndOn->setTime(0, 0);
    }

    /**
     * Whether any charge is still to come on or after `$today`: false for a
     * fixed term whose last billing date has passed, so totals drop it.
     */
    public function hasChargesRemaining(DateTimeImmutable $today): bool
    {
        if ($this->paymentsEndOn === null) {
            return true;
        }

        $next = $this->nextChargeDate();
        if ($next === null) {
            return $this->paymentsEndOn->setTime(0, 0) >= $today->setTime(0, 0);
        }

        $cycle = $this->billingCycle;
        $today = $today->setTime(0, 0);
        for ($i = 0; $cycle !== null && $next < $today && $i < 15_000; $i++) {
            $next = $cycle->advance($next, $this->cycleDays, $this->anchorDay, $this->cycleInterval);
        }

        return $this->chargesOn($next);
    }

    /**
     * Whether the contract has ended on a row that is still running — the
     * "Out of contract" insight, shown until the date is moved.
     */
    public function isOutOfContract(DateTimeImmutable $today): bool
    {
        return $this->contractEndsOn !== null
            && $this->isActive
            && $this->cancelledAt === null
            && $this->contractEndsOn->setTime(0, 0) < $today->setTime(0, 0);
    }

    public function isPrivate(): bool
    {
        return $this->visibility->isPrivate();
    }

    /**
     * Equivalent cost over one month, in minor units, or null when the entry
     * is not a recurring one and therefore has no meaningful monthly figure.
     */
    public function monthlyMinor(): ?int
    {
        if (!$this->type->countsTowardsRecurringTotals() || $this->billingCycle === null) {
            return null;
        }

        return $this->billingCycle->monthlyMinor($this->price->amountMinor, $this->cycleDays, $this->cycleInterval);
    }

    public function yearlyMinor(): ?int
    {
        if (!$this->type->countsTowardsRecurringTotals() || $this->billingCycle === null) {
            return null;
        }

        return $this->billingCycle->annualMinor($this->price->amountMinor, $this->cycleDays, $this->cycleInterval);
    }

    /**
     * The next date money actually changes hands.
     *
     * For a running trial that is the conversion date, not `nextPaymentDate` —
     * a trial has no payment date yet, and whatever the column holds is either
     * null or a leftover default. Anything reasoning about the next charge has
     * to ask this rather than reading the column, or it will quietly answer for
     * a payment that is not going to happen.
     */
    public function nextChargeDate(): ?DateTimeImmutable
    {
        if ($this->isTrial && $this->trialEndDate !== null) {
            return $this->trialEndDate;
        }

        return $this->nextPaymentDate;
    }

    /**
     * The last day on which the subscription can be cancelled and still avoid
     * the next charge.
     *
     * Measured from the next *charge*, which for a trial is its conversion.
     * Getting that wrong would tell somebody on a 30-day notice that their
     * deadline had already passed when in fact they had a fortnight — the
     * single most damaging thing this page could do.
     */
    public function cancellationDeadline(): ?DateTimeImmutable
    {
        $charge = $this->nextChargeDate();
        if ($charge === null) {
            return null;
        }

        return $this->noticePeriod->deadlineBefore($charge);
    }

    /**
     * Whether cancelling today still avoids a charge falling on `$charge`.
     *
     * It does when there is no notice period, or when the notice deadline
     * before that charge is today or later. Every charge it does not avoid is
     * committed: it happens whatever is done now. The deadline only moves
     * forward as the charge does, so the committed charges are always the
     * first few and never one in the middle.
     *
     * One rule, read by the scenario planner and by "If you cancelled", so the
     * two cannot disagree about which charges a cancellation saves.
     */
    public function isChargeAvoidable(DateTimeImmutable $charge, DateTimeImmutable $today): bool
    {
        $deadline = $this->noticePeriod->deadlineBefore($charge);

        return $deadline === null || $deadline->setTime(0, 0) >= $today->setTime(0, 0);
    }

    /**
     * A copy on other terms from its next charge: a price, and optionally a
     * billing cycle. What a scenario's "change price" means.
     *
     * A running trial keeps its trial and changes what it converts to, since
     * the conversion is its next charge. Anything else is billed from
     * `$from` — its next charge — on the new cycle, anchored on that day when
     * the cycle changes and on its own anchor when it does not.
     *
     * A scenario names a cycle, not an interval: "same cycle" (null) keeps
     * the interval, and a named cycle runs every one of its units — "Monthly"
     * chosen for a six-monthly policy is a change to every month.
     */
    public function withTerms(
        Money $price,
        ?BillingCycle $cycle,
        ?int $cycleDays,
        DateTimeImmutable $from,
    ): self {
        if ($this->isTrial) {
            $interval = $cycle === null ? $this->cycleIntervalAfterConversion() : 1;
            $cycle ??= $this->billingCycleAfterConversion();

            return $this->with([
                'convertsToPrice' => $price,
                'convertsToBillingCycle' => $cycle,
                'convertsToCycleDays' => $cycle === BillingCycle::CustomDays
                    ? ($cycleDays ?? $this->cycleDaysAfterConversion())
                    : null,
                'convertsToCycleInterval' => $interval,
            ]);
        }

        $changesCycle = $cycle !== null && ($cycle !== $this->billingCycle || $this->cycleInterval !== 1);
        $interval = $cycle === null ? $this->cycleInterval : 1;
        $cycle ??= $this->billingCycle;

        return $this->with([
            'price' => $price,
            'billingCycle' => $cycle,
            'cycleDays' => $cycle === BillingCycle::CustomDays ? ($cycleDays ?? $this->cycleDays) : null,
            'cycleInterval' => $interval,
            'nextPaymentDate' => $from,
            'anchorDay' => $changesCycle ? (int) $from->format('j') : $this->anchorDay,
        ]);
    }

    /**
     * This subscription's own reminder lead times, or null to use the user's.
     *
     * An empty string is not the same as null and the difference is the point:
     * null means "whatever my preference says", an empty list means "never
     * remind me about this one". A single expensive annual policy and a £2
     * monthly app want different answers, and neither wants the other's.
     *
     * @return list<int>|null
     */
    public function reminderDaysList(): ?array
    {
        if ($this->reminderDays === null) {
            return null;
        }

        $days = [];
        foreach (explode(',', $this->reminderDays) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $days[] = (int) $part;
            }
        }

        rsort($days);

        return array_values(array_unique($days));
    }

    public function daysUntilNextPayment(DateTimeImmutable $today): ?int
    {
        return $this->daysUntil($this->nextPaymentDate, $today);
    }

    /**
     * True while the trial is still running.
     *
     * A trial whose end date has passed is not "a trial that ended" — it is a
     * paid subscription whose conversion has not been applied yet. The
     * catch-up turns one into the other.
     */
    public function isTrialActiveOn(DateTimeImmutable $date): bool
    {
        return $this->isTrial
            && $this->trialEndDate !== null
            && $this->trialEndDate->setTime(0, 0) >= $date->setTime(0, 0);
    }

    public function trialHasEndedBy(DateTimeImmutable $date): bool
    {
        return $this->isTrial
            && $this->trialEndDate !== null
            && $this->trialEndDate->setTime(0, 0) < $date->setTime(0, 0);
    }

    public function daysUntilTrialEnds(DateTimeImmutable $today): ?int
    {
        return $this->daysUntil($this->trialEndDate, $today);
    }

    /**
     * What this subscription will cost once the trial converts — the
     * converts-to price when one was recorded, and otherwise the price it
     * already has.
     */
    public function priceAfterConversion(): Money
    {
        return $this->convertsToPrice ?? $this->price;
    }

    public function billingCycleAfterConversion(): ?BillingCycle
    {
        return $this->convertsToBillingCycle ?? $this->billingCycle;
    }

    public function cycleDaysAfterConversion(): ?int
    {
        return $this->convertsToBillingCycle !== null ? $this->convertsToCycleDays : $this->cycleDays;
    }

    public function cycleIntervalAfterConversion(): int
    {
        return $this->convertsToBillingCycle !== null ? ($this->convertsToCycleInterval ?? 1) : $this->cycleInterval;
    }

    /**
     * Cost per recorded use, in minor units, or null when there is nothing to
     * divide by.
     *
     * Measured against the period the count covers rather than against a single
     * bill: forty uses is excellent over a month and poor over three years, and
     * a figure that ignored the period would rate them the same.
     */
    public function costPerUseMinor(DateTimeImmutable $today): ?int
    {
        if ($this->usageCount <= 0) {
            return null;
        }

        $monthly = $this->monthlyMinor();
        if ($monthly === null) {
            return null;
        }

        $months = max(1, $this->usageMonths($today));

        return Rounding::divide($monthly * $months, $this->usageCount);
    }

    /**
     * Whole months the usage count covers, at least one.
     */
    public function usageMonths(DateTimeImmutable $today): int
    {
        $since = $this->usageCountedSince ?? $this->startDate ?? $this->createdAt;

        $months = (int) $since->setTime(0, 0)->diff($today->setTime(0, 0))->format('%r%a');

        return max(1, (int) round($months / 30.44));
    }

    /**
     * A copy with some properties replaced. The constructor's parameters are
     * its properties, so the copy is built through it and stays readonly.
     *
     * @param array<string, mixed> $changes
     */
    private function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    private function daysUntil(?DateTimeImmutable $date, DateTimeImmutable $today): ?int
    {
        if ($date === null) {
            return null;
        }

        return (int) $today->setTime(0, 0)->diff($date->setTime(0, 0))->format('%r%a');
    }

    /**
     * Where the payment date goes when a paused row is resumed, or null when
     * it can stay where it is.
     *
     * Nothing was billed while the row was paused, so a date the pause left
     * behind moves on, by whole cycles, to the first billing date that is
     * today or later — and the catch-up then finds no passed dates to write
     * as charges (the ledger records the ones stepped over as skipped). A
     * date that is still ahead is left alone.
     *
     * `$from` stands in for the stored date when the row is being resumed
     * with a date somebody has just submitted.
     */
    public function resumedPaymentDate(DateTimeImmutable $today, ?DateTimeImmutable $from = null): ?DateTimeImmutable
    {
        return $this->walkPause($today, $from)['resumed'];
    }

    /**
     * The billing dates a pause stepped over — from the payment date it left
     * behind up to, not including, `resumedPaymentDate()` — which the ledger
     * records as skipped.
     *
     * @return list<DateTimeImmutable>
     */
    public function pausedDates(DateTimeImmutable $today, ?DateTimeImmutable $from = null): array
    {
        return $this->walkPause($today, $from)['dates'];
    }

    /**
     * @return array{resumed: ?DateTimeImmutable, dates: list<DateTimeImmutable>}
     */
    private function walkPause(DateTimeImmutable $today, ?DateTimeImmutable $from): array
    {
        $cycle = $this->billingCycle;
        $date = $from ?? $this->nextPaymentDate;
        $today = $today->setTime(0, 0);

        if ($cycle === null || $date === null || !$this->type->countsTowardsRecurringTotals() || $date >= $today) {
            return ['resumed' => null, 'dates' => []];
        }

        // The catch-up's guard, for a date centuries behind.
        $dates = [];
        for ($i = 0; $date < $today && $i < 15_000; $i++) {
            $dates[] = $date;
            $date = $cycle->advance($date, $this->cycleDays, $this->anchorDay, $this->cycleInterval);
        }

        return ['resumed' => $date, 'dates' => $dates];
    }
}
