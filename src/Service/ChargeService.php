<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AmountTooLargeException;
use App\Domain\ActualsAverage;
use App\Domain\ChargeSource;
use App\Domain\ChargeStatus;
use App\Domain\Entity\Charge;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\SubscriptionType;
use App\Repository\ChargeRepository;
use App\Repository\SubscriptionRepository;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use App\Support\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The payment ledger: what was charged, not just what is due.
 *
 * Rows arrive three ways, and each has one door here. The catch-up writes one
 * for every billing date it rolls past (`recordPassed()`), at the price in
 * force on that date; a member adds one for a charge that fell off the cycle
 * (`addManual()`); and `ledger:backfill` rebuilds the months before the ledger
 * existed (`backfill()`). A member may then correct a charge — record what
 * was really taken, mark it as one that did not happen, or undo either.
 *
 * **This is not bank sync.** Nothing here reads a bank or a provider; every
 * row is Renovo's own billing rules or a member's word.
 *
 * A charge on an introductory or promotional price carries a **reference**,
 * what it would have cost without the offer, written with it and frozen
 * (`ChargeSchedule::referenceAsAt()`); a member recording a lower actual may
 * say it was a discount, which makes the expected amount the reference. What
 * the two save is `SavingsService`'s to add up.
 *
 * Every write asks the write predicate, so a Viewer changes nothing and a
 * Contributor changes only the charges on rows they own. The permission that
 * gates the routes is `ManageCharges`; this class is what holds when a route
 * is reached anyway.
 */
final class ChargeService
{
    /** How many charges the Payments card shows a page. */
    public const PER_PAGE = 12;

    public function __construct(
        private readonly ChargeRepository $charges,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PriceHistoryService $priceHistory,
        private readonly ChargeSchedule $schedule,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Write a charge for each billing date that has passed, skipping any date
     * that already has one.
     *
     * Called by the catch-up inside the transaction that moves the payment
     * date, so either both happen or neither does. Each charge is priced at
     * the history's price on its own date, so a rise that took effect between
     * two of them is reflected in the second.
     *
     * @param list<DateTimeImmutable> $dates
     * @return int How many rows were written.
     */
    public function recordPassed(Scope $scope, Subscription $subscription, array $dates): int
    {
        if ($dates === []) {
            return 0;
        }

        $existing = $this->charges->existingDates($scope, $subscription->id);
        // The history once, not once per date: a catch-up months late prices
        // every passed date from the same rows.
        $history = $this->priceHistory->historyFor($scope, $subscription->id);
        $written = 0;

        foreach ($dates as $date) {
            $key = $date->format('Y-m-d');
            $due = ChargeSchedule::priceAsAt($history, $date) ?? $subscription->price;
            $reference = ChargeSchedule::referenceAsAt($history, $date);

            // A payment a member added by hand on this billing date is this
            // date's charge, with what they paid as its actual: it becomes the
            // ledger's own row, so Undo later puts back the price due rather
            // than leaving the date with nothing (K70).
            if (!$this->claimManual($scope, $subscription, $date, $due, ChargeSource::CatchUp, $existing, $reference)) {
                continue;
            }

            $this->charges->record(
                $scope,
                $subscription->id,
                $subscription->ownerUserId,
                $date,
                $due,
                ChargeSource::CatchUp,
                now: $this->clock->now(),
                reference: $reference,
            );
            $existing[$key] = ChargeSource::CatchUp;
            $written++;
        }

        return $written;
    }

    /**
     * Record the billing dates a pause stepped over as skipped: nothing was
     * billed, and the ledger says so rather than leaving them empty — which
     * `ledger:backfill` would otherwise fill with the reconstruction's
     * charges. A payment a member added by hand on one of those dates — a
     * month that was in fact billed — becomes its paused row, with what they
     * entered as its actual, so Undo puts the month back as not billed rather
     * than leaving it empty — one in another currency keeps its own amounts.
     * Called in the same transaction as the resume moves the payment date,
     * before the row is saved.
     *
     * @param list<DateTimeImmutable> $dates From `Subscription::pausedDates()`.
     * @return int How many rows were written.
     */
    public function recordPaused(Scope $scope, Subscription $subscription, array $dates): int
    {
        if ($dates === []) {
            return 0;
        }

        $existing = $this->charges->existingDates($scope, $subscription->id);
        $history = $this->priceHistory->historyFor($scope, $subscription->id);
        $written = 0;

        foreach ($dates as $date) {
            $due = ChargeSchedule::priceAsAt($history, $date) ?? $subscription->price;
            // Written on a month that was not billed too: a member who marks
            // it billed after all is then credited with the offer, as a
            // charge the catch-up wrote would be.
            $reference = ChargeSchedule::referenceAsAt($history, $date);
            if (!$this->claimManual($scope, $subscription, $date, $due, ChargeSource::Paused, $existing, $reference)) {
                // One in another currency than the price due keeps its own
                // amounts, but is still the month's paused row: Undo puts it
                // back as not billed rather than leaving the month empty.
                if (($existing[$date->format('Y-m-d')] ?? null) === ChargeSource::Manual) {
                    $this->charges->pauseManual($scope, $subscription->id, $date);
                }
                continue;
            }

            $this->charges->record(
                $scope,
                $subscription->id,
                $subscription->ownerUserId,
                $date,
                $due,
                ChargeSource::Paused,
                now: $this->clock->now(),
                status: ChargeStatus::Skipped,
                reference: $reference,
            );
            $written++;
        }

        return $written;
    }

    /**
     * Write a one-off or lifetime entry's single charge, once.
     *
     * One-offs have no payment date to compare-and-set, so two catch-ups at
     * the same moment can both find the entry uncharged. The unique key
     * decides: the second insert collides, and that is the answer — the row
     * is there — rather than an error. Called outside any transaction, which
     * a failed statement would poison on PostgreSQL.
     *
     * @return bool Whether this call wrote it.
     */
    public function recordOneOff(Scope $scope, Subscription $entry): bool
    {
        if ($entry->startDate === null) {
            return false;
        }

        $price = $this->priceHistory->priceOn($scope, $entry->id, $entry->startDate) ?? $entry->price;
        $reference = ChargeSchedule::referenceAsAt(
            $this->priceHistory->historyFor($scope, $entry->id),
            $entry->startDate,
        );
        // Judged in the entry's own currency, as `findUnchargedOneOffs()`
        // selects it: a currency changed since the date would otherwise have
        // the entry chosen, and missed, on every catch-up.
        if ($price->currency !== $entry->price->currency) {
            $price = $entry->price;
            $reference = null;
        }

        // A payment a member added by hand on the entry's date is its charge,
        // as the backfill makes it: then a later change of the date moves it,
        // rather than writing a second beside it.
        $adopted = $this->charges->adoptManual(
            $scope,
            $entry->id,
            $entry->startDate,
            $price,
            ChargeSource::OneOff,
            $reference,
        );
        if ($adopted) {
            return true;
        }

        $id = $this->charges->recordUnlessTaken(
            $scope,
            $entry->id,
            $entry->ownerUserId,
            $entry->startDate,
            $price,
            ChargeSource::OneOff,
            now: $this->clock->now(),
            reference: $reference,
        );
        if ($id === null) {
            return false;
        }

        // An invoice filed against the entry's charge before its date was moved
        // into the future comes back to it, by the date it carries.
        $this->charges->linkInvoicesByDate($scope, $entry->id, $entry->startDate, $id);

        return true;
    }

    /**
     * Whether giving a one-off or lifetime entry this date would put its own
     * charge on a day that already has another — which the edit is refused
     * for, rather than one of the two being dropped.
     *
     * Asked of the type the edit submits (K71): a row becoming recurring has
     * no single charge to place, and keeps its one-off charge as history.
     */
    public function oneOffDateTaken(
        Scope $scope,
        Subscription $before,
        SubscriptionType $type,
        ?DateTimeImmutable $start,
    ): bool {
        if (
            $type->countsTowardsRecurringTotals()
            || $start === null
            || $start == $before->startDate
        ) {
            return false;
        }

        $taken = $this->charges->existingDates($scope, $before->id)[$start->format('Y-m-d')] ?? null;

        return $taken !== null;
    }

    /**
     * Keep a one-off or lifetime entry's charge on the entry's date when that
     * date is edited. Recurring rows are not touched: their charges are the
     * billing dates that passed, which an edit does not rewrite.
     *
     * Decided by the type the edit submits (K71). A one-off turning recurring
     * keeps its charge where it was, as history; a recurring row turning into
     * a one-off has no own charge yet, and gets one on its date from the
     * catch-up.
     */
    public function followDateChange(
        Scope $scope,
        Subscription $before,
        SubscriptionType $type,
        ?DateTimeImmutable $start,
    ): void {
        if (
            $type->countsTowardsRecurringTotals()
            || $before->startDate === null
            || $start === null
            || $start == $before->startDate
        ) {
            return;
        }

        $this->charges->followOneOffDate(
            $scope,
            $before->id,
            $before->startDate,
            $start,
            $this->clock->today(),
            $this->clock->now(),
            !$before->type->countsTowardsRecurringTotals(),
        );
    }

    /**
     * One page of a subscription's charges, newest first.
     *
     * @return array{charges: list<Charge>, page: int, pages: int, total: int}
     */
    public function page(Scope $scope, int $subscriptionId, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $total = $this->charges->countForSubscription($scope, $subscriptionId);
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $pages);

        return [
            'charges' => $this->charges->findForSubscription($scope, $subscriptionId, $page, $perPage),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /**
     * Every charge of one subscription, newest first — the API's list.
     *
     * @return list<Charge>
     */
    public function forSubscription(Scope $scope, int $subscriptionId): array
    {
        return $this->charges->findForSubscription($scope, $subscriptionId);
    }

    /**
     * Every charge in scope, oldest first — export and backup.
     *
     * @return list<Charge>
     */
    public function all(Scope $scope): array
    {
        return $this->charges->findAllInScope($scope);
    }

    /**
     * The latest date a payment can be added for.
     */
    public function today(): DateTimeImmutable
    {
        return $this->clock->today();
    }

    public function find(Scope $scope, int $id): ?Charge
    {
        return $this->charges->find($scope, $id);
    }

    /**
     * Whether this scope may change the charge — what decides if its controls
     * are drawn. Not what refuses a forged request: `adjust()` does that.
     */
    public function mayChange(Scope $scope, Charge $charge): bool
    {
        return $scope->canWrite()
            && $scope->mayWriteRow($charge->householdId, $charge->ownerUserId)
            && $this->subscriptions->isWritable($scope, $charge->subscriptionId);
    }

    /**
     * Whether this scope may add a charge to the subscription.
     */
    public function mayAddTo(Scope $scope, int $subscriptionId): bool
    {
        return $scope->canWrite() && $this->subscriptions->isWritable($scope, $subscriptionId);
    }

    /**
     * The earliest month the ledger holds in scope, as the first of that month.
     */
    public function earliestMonth(Scope $scope): ?DateTimeImmutable
    {
        $earliest = $this->charges->earliestDueDate($scope);

        return $earliest?->modify('first day of this month')->setTime(0, 0);
    }

    /**
     * Record what was really charged, with an optional note.
     *
     * The status goes back to charged: recording an amount says the charge
     * happened. `discount` ticked says a lower amount was a discount, which
     * makes the expected amount the charge's reference; left unticked, a
     * discount marked before is taken off. An offer's reference is kept
     * either way.
     *
     * @param array<string, mixed> $input          `actual`, `note`, `discount`.
     * @param int|null             $subscriptionId When the request names the
     *                                             subscription, the charge must
     *                                             be one of its own.
     * @throws ValidationException
     */
    public function recordActual(Scope $scope, int $chargeId, array $input, ?int $subscriptionId = null): Charge
    {
        $charge = $this->writable($scope, $chargeId, $subscriptionId);

        $errors = [];
        $actual = $this->amount($this->str($input, 'actual'), $charge->expected->currency, 'actual', $errors);
        $note = $this->note($input, $errors);
        $reference = $actual === null
            ? $charge->referenceMinor
            : $this->referenceAfter($charge, $actual->amountMinor, $this->str($input, 'discount') === '1', $errors);

        if ($errors !== [] || $actual === null) {
            throw new ValidationException($errors === [] ? ['actual' => 'error.charge.actual_required'] : $errors);
        }

        $this->charges->adjust($scope, $charge->id, $actual->amountMinor, ChargeStatus::Charged, $note, $reference);

        return $this->reread($scope, $charge->id);
    }

    /**
     * Mark a charge as one that did not happen: waived, missed or refunded in
     * full. It then counts as nothing.
     *
     * @param array<string, mixed> $input `note`.
     * @throws ValidationException
     */
    public function skip(Scope $scope, int $chargeId, array $input = [], ?int $subscriptionId = null): Charge
    {
        $charge = $this->writable($scope, $chargeId, $subscriptionId);

        $errors = [];
        $note = $this->note($input, $errors, $charge->note);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // The reference stays: a skipped charge saves nothing whatever it
        // carries, and Undo brings the charge back as it was.
        $this->charges->adjust($scope, $charge->id, null, ChargeStatus::Skipped, $note, $charge->referenceMinor);

        return $this->reread($scope, $charge->id);
    }

    /**
     * Put a charge back as the ledger wrote it — or, for one a member added
     * by hand, remove it.
     *
     * @return Charge|null The charge as it now stands; null when it was a
     *                     manual row and has gone.
     * @throws ValidationException
     */
    public function undo(Scope $scope, int $chargeId, ?int $subscriptionId = null): ?Charge
    {
        $charge = $this->writable($scope, $chargeId, $subscriptionId);

        // A payment added by hand is still manual only while no billing date
        // has claimed it — the catch-up and the backfill adopt one that falls
        // on theirs — so removing it leaves no billing date without a charge.
        if ($charge->isManual()) {
            $this->charges->deleteManual($scope, $charge->id);

            return null;
        }

        // A discount a member marked goes with the actual it was about; an
        // offer's reference is the ledger's, and stays.
        $reference = $charge->isDiscount() ? null : $charge->referenceMinor;
        $this->charges->adjust($scope, $charge->id, null, $charge->writtenStatus(), null, $reference);

        return $this->reread($scope, $charge->id);
    }

    /**
     * Change any of a charge's actual, status and note at once — the API's
     * PATCH. Fields not given keep their value.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function patch(Scope $scope, int $chargeId, array $input): Charge
    {
        $charge = $this->writable($scope, $chargeId);

        $errors = [];
        $actualMinor = $charge->actualMinor;
        if (array_key_exists('actual_minor', $input)) {
            $value = $input['actual_minor'];
            if ($value === null) {
                $actualMinor = null;
            } elseif (is_int($value) && $value >= 0) {
                $actualMinor = $value;
            } else {
                $errors['actual_minor'] = 'error.charge.minor_invalid';
            }
        }

        $discount = null;
        if (array_key_exists('is_discount', $input)) {
            if (is_bool($input['is_discount'])) {
                $discount = $input['is_discount'];
            } else {
                $errors['is_discount'] = 'error.charge.discount_invalid';
            }
        }

        // Recording an amount says the charge happened, as the web form's
        // Record amount does: an actual sent without a status un-skips it.
        $status = $actualMinor !== null && array_key_exists('actual_minor', $input)
            ? ChargeStatus::Charged
            : $charge->status;
        if (array_key_exists('status', $input)) {
            $status = is_string($input['status']) ? ChargeStatus::tryFrom($input['status']) : null;
            if ($status === null) {
                $errors['status'] = 'error.charge.status_invalid';
                $status = $charge->status;
            }
        }

        $note = array_key_exists('note', $input) ? $this->note($input, $errors) : $charge->note;

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // A skipped charge has no amount, so an actual recorded beside it would
        // be a figure nothing reads. Refused rather than quietly dropped.
        if ($status === ChargeStatus::Skipped && $actualMinor !== null && array_key_exists('actual_minor', $input)) {
            throw new ValidationException(['actual_minor' => 'error.charge.actual_on_skipped']);
        }

        // Skipping keeps the reference, as the web form's Skip does; any
        // other change settles it by the form's rule.
        $reference = $status === ChargeStatus::Skipped && $discount === null
            ? $charge->referenceMinor
            : $this->referenceAfter(
                $charge,
                $status === ChargeStatus::Skipped ? null : $actualMinor,
                $discount,
                $errors,
                'is_discount',
            );
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->charges->adjust(
            $scope,
            $charge->id,
            $status === ChargeStatus::Skipped ? null : $actualMinor,
            $status,
            $note,
            $reference,
        );

        return $this->reread($scope, $charge->id);
    }

    /**
     * The average of a varying bill's latest recorded amounts against its
     * estimate, or null for a row whose amount does not vary or that has no
     * recorded amount yet.
     */
    public function averageOfActuals(Scope $scope, Subscription $subscription): ?ActualsAverage
    {
        if (!$subscription->amountVaries) {
            return null;
        }

        return ActualsAverage::of(
            $this->charges->findForSubscription($scope, $subscription->id, 1, ActualsAverage::LOOK_BACK),
            $subscription->price,
        );
    }

    /**
     * Make a varying bill's estimate the average of its latest recorded
     * amounts: a manual price change from today, so it is in the price
     * history like any other edit.
     *
     * @throws ScopeViolationException when the row is not this scope's to change.
     * @throws ValidationException when there is no different average to use.
     */
    public function useAverageAsEstimate(Scope $scope, int $subscriptionId): Money
    {
        $subscription = $this->subscriptions->findForWrite($scope, $subscriptionId);
        if ($subscription === null) {
            throw ScopeViolationException::forRow('subscriptions', $subscriptionId);
        }

        $average = $this->averageOfActuals($scope, $subscription);
        if ($average === null || !$average->differs()) {
            throw new ValidationException(['estimate' => 'error.estimate.nothing_to_use']);
        }

        $this->priceHistory->recordCurrentPrice(
            $scope,
            $subscription->id,
            $average->average,
            PriceChangeSource::Manual,
            // The history row carries the subscription owner's id, as every
            // other price change does — not whoever pressed the button.
            $subscription->ownerUserId,
        );

        return $average->average;
    }

    /**
     * Add a charge that happened off the cycle — a one-time fee, a charge on a
     * day the schedule did not predict.
     *
     * Dated today or earlier: the ledger records what happened, and a charge
     * still to come is the forecast's. One charge a day per subscription, as
     * the unique key says, so a date that already has one is refused with a
     * message rather than a 500.
     *
     * @param array<string, mixed> $input `date`, `amount` (or `amount_minor`), `note`.
     * @throws ValidationException
     */
    public function addManual(Scope $scope, int $subscriptionId, array $input): Charge
    {
        $subscription = $this->subscriptions->findForWrite($scope, $subscriptionId);
        if ($subscription === null || !$scope->canWrite()) {
            throw new ValidationException(['subscription' => 'error.subscription.not_found']);
        }

        $errors = [];
        $date = $this->date($this->str($input, 'date'));
        if ($date === null) {
            $errors['date'] = 'error.charge.date_required';
        } elseif ($date > $this->clock->today()) {
            $errors['date'] = 'error.charge.date_future';
        } elseif (isset($this->charges->existingDates($scope, $subscription->id)[$date->format('Y-m-d')])) {
            $errors['date'] = 'error.charge.date_taken';
        }

        // The API sends minor units, as it does for every price; the form
        // sends what a member types.
        if (array_key_exists('amount_minor', $input)) {
            $minor = $input['amount_minor'];
            $amount = is_int($minor) && $minor >= 0 ? Money::of($minor, $subscription->price->currency) : null;
            if ($amount === null) {
                $errors['amount_minor'] = $minor === null
                    ? 'error.charge.amount_required'
                    : 'error.charge.minor_invalid';
            }
        } else {
            $amount = $this->amount($this->str($input, 'amount'), $subscription->price->currency, 'amount', $errors);
        }
        if ($amount === null && !isset($errors['amount']) && !isset($errors['amount_minor'])) {
            $errors['amount'] = 'error.charge.amount_required';
        }

        $note = $this->note($input, $errors);

        if ($errors !== [] || $date === null || $amount === null) {
            throw new ValidationException($errors);
        }

        // Checked above, and decided here: a catch-up writing the same date in
        // the meantime is a date taken, not a 500.
        $id = $this->charges->recordUnlessTaken(
            $scope,
            $subscription->id,
            $subscription->ownerUserId,
            $date,
            $amount,
            ChargeSource::Manual,
            note: $note,
            now: $this->clock->now(),
        );
        if ($id === null) {
            throw new ValidationException(['date' => 'error.charge.date_taken']);
        }

        return $this->reread($scope, $id);
    }

    /**
     * Rebuild the charges from before the ledger existed, for every
     * subscription this scope may write.
     *
     * Per subscription: the reconstruction's charges from its start date up
     * to, not including, its current `next_payment_date` — and before its
     * earliest catch-up row, so the backfill never writes beside the catch-up.
     * A date that already has a row is left alone whatever wrote it — except
     * a payment a member added by hand there, which becomes the date's charge
     * with what they entered kept as its actual — so a second run writes
     * nothing and a member's correction is never overwritten. Paused rows stop where their payment date stopped: the
     * reconstruction counts them through today, and the ledger does not
     * invent charges for months nobody was billed.
     *
     * One-off and lifetime entries get their single row on their date, marked
     * `one_off` rather than `reconstructed`, as the catch-up marks it.
     *
     * Every charge it writes carries its offer's reference, and every charge
     * already there on a promotional price that has none is given one —
     * written before references existed. One that has a reference keeps it.
     *
     * @return array{subscriptions: int, charges: int, references: int}
     */
    public function backfill(Scope $scope): array
    {
        if (!$scope->canWrite()) {
            return ['subscriptions' => 0, 'charges' => 0, 'references' => 0];
        }

        $today = $this->clock->today();
        $touched = 0;
        $written = 0;
        $references = 0;

        foreach ($this->subscriptions->findAllWritable($scope) as $subscription) {
            $existing = $this->charges->existingDates($scope, $subscription->id);
            $history = $this->priceHistory->historyFor($scope, $subscription->id);
            $references += $this->fillReferences($scope, $subscription, $history, $existing);

            $stop = $this->backfillStop($subscription, $existing, $today);
            if ($stop === null) {
                continue;
            }

            $count = 0;
            $rebuilt = $this->schedule->between(
                $subscription,
                $history,
                new DateTimeImmutable('0001-01-01'),
                $stop->modify('-1 day'),
            );

            foreach ($rebuilt as $charge) {
                $key = $charge['date']->format('Y-m-d');

                // A one-off's single charge is its own, whoever writes it, so a
                // later change of its date can find it.
                $source = $subscription->type->countsTowardsRecurringTotals()
                    ? ChargeSource::Reconstructed
                    : ChargeSource::OneOff;

                // As the catch-up does: a payment added by hand on a date the
                // reconstruction bills becomes that date's charge.
                $date = $charge['date'];
                $reference = ChargeSchedule::referenceAsAt($history, $date);
                $amount = $charge['amount'];
                if (!$this->claimManual($scope, $subscription, $date, $amount, $source, $existing, $reference)) {
                    continue;
                }

                $this->charges->record(
                    $scope,
                    $subscription->id,
                    $subscription->ownerUserId,
                    $charge['date'],
                    $charge['amount'],
                    $source,
                    now: $this->clock->now(),
                    reference: $reference,
                );
                $existing[$key] = $source;
                $count++;
            }

            if ($count > 0) {
                $touched++;
                $written += $count;
            }
        }

        return ['subscriptions' => $touched, 'charges' => $written, 'references' => $references];
    }

    /**
     * Give the charges a subscription already has on a promotional price the
     * reference they would have been written with, where they have none.
     *
     * @param list<\App\Domain\Entity\PriceChange> $history
     * @param array<string, ChargeSource> $existing
     * @return int How many were given one.
     */
    private function fillReferences(Scope $scope, Subscription $subscription, array $history, array $existing): int
    {
        $filled = 0;
        foreach ($existing as $key => $source) {
            if ($source === ChargeSource::Manual) {
                continue;
            }

            $date = new DateTimeImmutable($key);
            $reference = ChargeSchedule::referenceAsAt($history, $date);
            if ($reference !== null && $this->charges->fillReference($scope, $subscription->id, $date, $reference)) {
                $filled++;
            }
        }

        return $filled;
    }

    /**
     * Settle a billing date that may already have a charge, before a new one
     * is written for it: a payment a member added by hand there is adopted as
     * the date's charge. Whether the caller should still write one — true
     * only when the date has nothing on it.
     *
     * A manual payment the adoption missed is either gone (removed since the
     * dates were read), and the date is written after all, or in another
     * currency, and it stays the date's only charge. `$existing` is kept up
     * to date.
     *
     * @param array<string, ChargeSource> $existing
     */
    private function claimManual(
        Scope $scope,
        Subscription $subscription,
        DateTimeImmutable $date,
        Money $due,
        ChargeSource $source,
        array &$existing,
        ?Money $reference = null,
    ): bool {
        $key = $date->format('Y-m-d');
        if (!isset($existing[$key])) {
            return true;
        }
        if ($existing[$key] !== ChargeSource::Manual) {
            return false;
        }

        if ($this->charges->adoptManual($scope, $subscription->id, $date, $due, $source, $reference)) {
            $existing[$key] = $source;

            return false;
        }

        $existing = $this->charges->existingDates($scope, $subscription->id);

        return !isset($existing[$key]);
    }

    /**
     * The exclusive upper bound of a subscription's backfill, or null when
     * there is nothing to rebuild.
     *
     * @param array<string, ChargeSource> $existing
     */
    private function backfillStop(
        Subscription $subscription,
        array $existing,
        DateTimeImmutable $today,
    ): ?DateTimeImmutable {
        if ($subscription->startDate === null) {
            return null;
        }

        $stop = $today->modify('+1 day');

        if ($subscription->type->countsTowardsRecurringTotals()) {
            if ($subscription->nextPaymentDate === null) {
                return null;
            }
            $stop = min($stop, $subscription->nextPaymentDate);
        }

        foreach ($existing as $date => $source) {
            // The catch-up's rows and a resume's paused months are the ledger's
            // own record; the backfill stops before the first of them.
            if ($source === ChargeSource::CatchUp || $source === ChargeSource::Paused) {
                $stop = min($stop, new DateTimeImmutable($date));
            }
        }

        return $stop;
    }

    /**
     * The reference a charge has once its actual is `$actualMinor`, by the
     * rule for **This was a discount**.
     *
     * An offer's reference is the ledger's and stays, whatever is said; a
     * discount is refused on such a charge, since the offer already counts a
     * lower actual. Otherwise a discount said to be one makes the expected
     * amount the reference — refused unless the actual is below it — and one
     * said not to be takes it off. Not said at all (`null`, an API PATCH
     * without the field), a discount marked before stays while the actual is
     * still below the expected amount.
     *
     * @param array<string, string> $errors
     */
    private function referenceAfter(
        Charge $charge,
        ?int $actualMinor,
        ?bool $discount,
        array &$errors,
        string $field = 'discount',
    ): ?int {
        $expected = $charge->expected->amountMinor;

        if (!$charge->mayMarkDiscount()) {
            if ($discount === true) {
                $errors[$field] = 'error.charge.discount_on_offer';
            }

            return $charge->referenceMinor;
        }

        $lower = $actualMinor !== null && $actualMinor < $expected;
        if ($discount === null) {
            return $charge->isDiscount() && $lower ? $expected : null;
        }
        if (!$discount) {
            return null;
        }
        if (!$lower) {
            $errors[$field] = 'error.charge.discount_not_lower';

            return null;
        }

        return $expected;
    }

    /**
     * @throws ValidationException when the charge is absent or not this
     *         scope's to change.
     */
    private function writable(Scope $scope, int $chargeId, ?int $subscriptionId = null): Charge
    {
        $charge = $this->charges->find($scope, $chargeId);
        if (
            $charge === null
            || ($subscriptionId !== null && $charge->subscriptionId !== $subscriptionId)
            || !$scope->canWrite()
            || !$this->charges->isWritable($scope, $chargeId)
            // The parent too: a charge is changed only by somebody who may
            // change the subscription it belongs to.
            || !$this->subscriptions->isWritable($scope, $charge->subscriptionId)
        ) {
            throw new ValidationException(['charge' => 'error.charge.not_found']);
        }

        return $charge;
    }

    private function reread(Scope $scope, int $id): Charge
    {
        $charge = $this->charges->find($scope, $id);
        if ($charge === null) {
            throw new ValidationException(['charge' => 'error.charge.not_found']);
        }

        return $charge;
    }

    /**
     * @param array<string, string> $errors
     */
    private function amount(string $raw, string $currency, string $field, array &$errors): ?Money
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            $amount = Money::fromUserInput($raw, $currency);
        } catch (AmountTooLargeException) {
            $errors[$field] = 'error.price.too_large';

            return null;
        } catch (InvalidArgumentException) {
            $errors[$field] = 'error.price.invalid';

            return null;
        }

        if ($amount->isNegative()) {
            $errors[$field] = 'error.price.negative';

            return null;
        }

        return $amount;
    }

    /**
     * @param array<string, mixed>  $input
     * @param array<string, string> $errors
     */
    private function note(array $input, array &$errors, ?string $default = null): ?string
    {
        if (!array_key_exists('note', $input)) {
            return $default;
        }

        if (is_array($input['note']) || is_bool($input['note'])) {
            $errors['note'] = 'error.json.wrong_type';

            return $default;
        }

        $note = trim($this->str($input, 'note'));
        if (mb_strlen($note) > 255) {
            $errors['note'] = 'error.note.too_long_255';
        }

        return $note === '' ? null : $note;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function str(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false || $date->format('Y-m-d') !== $value ? null : $date;
    }
}
