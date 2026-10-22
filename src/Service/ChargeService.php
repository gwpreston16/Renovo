<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ChargeSource;
use App\Domain\ChargeStatus;
use App\Domain\Entity\Charge;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Repository\ChargeRepository;
use App\Repository\SubscriptionRepository;
use App\Security\Scope;
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
        $written = 0;

        foreach ($dates as $date) {
            $key = $date->format('Y-m-d');
            if (isset($existing[$key])) {
                continue;
            }

            $this->charges->record(
                $scope,
                $subscription->id,
                $subscription->ownerUserId,
                $date,
                $this->priceOn($scope, $subscription, $date),
                ChargeSource::CatchUp,
                now: $this->clock->now(),
            );
            $existing[$key] = ChargeSource::CatchUp;
            $written++;
        }

        return $written;
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
     * happened.
     *
     * @param array<string, mixed> $input          `actual`, `note`.
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

        if ($errors !== [] || $actual === null) {
            throw new ValidationException($errors === [] ? ['actual' => 'error.charge.actual_required'] : $errors);
        }

        $this->charges->adjust($scope, $charge->id, $actual->amountMinor, ChargeStatus::Charged, $note);

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

        $this->charges->adjust($scope, $charge->id, null, ChargeStatus::Skipped, $note);

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

        if ($charge->isManual()) {
            $this->charges->deleteManual($scope, $charge->id);

            return null;
        }

        $this->charges->adjust($scope, $charge->id, null, ChargeStatus::Charged, null);

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
                $errors['actual_minor'] = 'error.price.invalid';
            }
        }

        $status = $charge->status;
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

        $this->charges->adjust(
            $scope,
            $charge->id,
            $status === ChargeStatus::Skipped ? null : $actualMinor,
            $status,
            $note,
        );

        return $this->reread($scope, $charge->id);
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
                $errors['amount_minor'] = 'error.price.invalid';
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

        $id = $this->charges->record(
            $scope,
            $subscription->id,
            $subscription->ownerUserId,
            $date,
            $amount,
            ChargeSource::Manual,
            note: $note,
            now: $this->clock->now(),
        );

        return $this->reread($scope, $id);
    }

    /**
     * Rebuild the charges from before the ledger existed, for every
     * subscription this scope may write.
     *
     * Per subscription: the reconstruction's charges from its start date up
     * to, not including, its current `next_payment_date` — and before its
     * earliest catch-up row, so the backfill never writes beside the catch-up.
     * A date that already has a row is left alone whatever wrote it, so a
     * second run writes nothing and a member's correction is never
     * overwritten. Paused rows stop where their payment date stopped: the
     * reconstruction counts them through today, and the ledger does not
     * invent charges for months nobody was billed.
     *
     * One-off and lifetime entries get their single row on their date.
     *
     * @return array{subscriptions: int, charges: int}
     */
    public function backfill(Scope $scope): array
    {
        if (!$scope->canWrite()) {
            return ['subscriptions' => 0, 'charges' => 0];
        }

        $today = $this->clock->today();
        $touched = 0;
        $written = 0;

        foreach ($this->subscriptions->findAllWritable($scope) as $subscription) {
            $existing = $this->charges->existingDates($scope, $subscription->id);
            $stop = $this->backfillStop($subscription, $existing, $today);
            if ($stop === null) {
                continue;
            }

            $count = 0;
            $rebuilt = $this->schedule->between(
                $subscription,
                $this->priceHistory->historyFor($scope, $subscription->id),
                new DateTimeImmutable('0001-01-01'),
                $stop->modify('-1 day'),
            );

            foreach ($rebuilt as $charge) {
                $key = $charge['date']->format('Y-m-d');
                if (isset($existing[$key])) {
                    continue;
                }

                $this->charges->record(
                    $scope,
                    $subscription->id,
                    $subscription->ownerUserId,
                    $charge['date'],
                    $charge['amount'],
                    ChargeSource::Reconstructed,
                    now: $this->clock->now(),
                );
                $existing[$key] = ChargeSource::Reconstructed;
                $count++;
            }

            if ($count > 0) {
                $touched++;
                $written += $count;
            }
        }

        return ['subscriptions' => $touched, 'charges' => $written];
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
            if ($source === ChargeSource::CatchUp) {
                $stop = min($stop, new DateTimeImmutable($date));
            }
        }

        return $stop;
    }

    private function priceOn(Scope $scope, Subscription $subscription, DateTimeImmutable $date): Money
    {
        return $this->priceHistory->priceOn($scope, $subscription->id, $date) ?? $subscription->price;
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
