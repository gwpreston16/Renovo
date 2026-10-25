<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Currency;
use App\Domain\Entity\PriceChange;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\PromoOffer;
use App\Domain\Rounding;
use App\Persistence\Database;
use App\Repository\PriceHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What a subscription has cost, does cost, and is about to cost.
 *
 * The model is one immutable row per price, each with the date it takes
 * effect. Nothing overwrites anything:
 *
 *  - the **current** price is the latest row whose date has arrived;
 *  - a **scheduled** change is a row whose date has not;
 *  - the **history** is all of them, which is what the trend view draws.
 *
 * `subscriptions.price_minor` is a denormalisation of the first of those. Every
 * write that changes which row is current updates it in the same transaction,
 * which is the invariant that keeps the list view and the trend view telling
 * the same story. `applyDueChanges()` is the repair for the one case the write
 * path cannot cover — a scheduled row becoming current merely because time
 * passed, with nobody editing anything.
 *
 * @phpstan-type HouseholdChange array{
 *     subscription: Subscription,
 *     change: PriceChange,
 *     from: Money,
 *     to: Money,
 *     kind: 'rise'|'cut'|'unchanged'|'converted',
 *     is_rise: bool,
 *     ends_offer: bool,
 *     is_scheduled: bool,
 *     difference_minor: int|null,
 *     percent_tenths: int|null,
 *     annual_minor: int|null
 * }
 * @phpstan-type Promotion array{
 *     change: PriceChange,
 *     ends_on: DateTimeImmutable|null,
 *     then: Money|null
 * }
 */
final class PriceHistoryService
{
    /**
     * The note on the row an offer's end writes. Stored, like the trial
     * conversion's, as plain text: a note is the row's own words, and the
     * label beside it is what is translated.
     */
    public const OFFER_END_NOTE = 'Intro offer ended.';

    public function __construct(
        private readonly PriceHistoryRepository $history,
        private readonly SubscriptionRepository $subscriptions,
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<PriceChange>
     */
    public function historyFor(Scope $scope, int $subscriptionId): array
    {
        return $this->history->findForSubscription($scope, $subscriptionId);
    }

    /**
     * The price in force on a date — used by the forecast and by the
     * year-over-year reconstruction, both of which ask about the past and the
     * future rather than about now.
     */
    public function priceOn(Scope $scope, int $subscriptionId, DateTimeImmutable $date): ?Money
    {
        return $this->history->findEffectiveOn($scope, $subscriptionId, $date)?->price;
    }

    public function nextScheduledChange(Scope $scope, int $subscriptionId): ?PriceChange
    {
        return $this->history->findNextScheduled($scope, $subscriptionId, $this->clock->today());
    }

    /**
     * Every announced-but-not-yet-applied change in scope, keyed by
     * subscription id.
     *
     * @return array<int, list<PriceChange>>
     */
    public function scheduledChanges(Scope $scope): array
    {
        return $this->history->findScheduledAfter($scope, $this->clock->today());
    }

    /**
     * Every recorded price in scope, keyed by subscription id, oldest first.
     *
     * The bulk form of `historyFor()`, for a caller that has a page full of
     * subscriptions and a question about each of them. One query rather than
     * one per row.
     *
     * @return array<int, list<PriceChange>>
     */
    public function historyBySubscription(Scope $scope): array
    {
        return $this->history->findAllBySubscription($scope);
    }

    /**
     * Every recorded change across the given subscriptions, newest first.
     *
     * The analytics screen's household price history. Each row is one price
     * paired with the one before it in the same subscription's history, in
     * that subscription's own currency — nothing here is converted.
     *
     * Taken over the subscriptions the caller already loaded through the
     * scoping layer, not over every history row in scope: a row's history is
     * listed only when the row itself is visible, so a private subscription's
     * changes reach its payer and nobody else, whatever the history table's
     * own predicate would allow.
     *
     * Three rows are not changes and are left out, or labelled:
     *
     *  - the **first** price has nothing before it to have changed from;
     *  - a **trial conversion** is a trial ending, not a price moving (the
     *    same guard the insight rules keep);
     *  - a **currency change** is listed as `converted`, with no difference
     *    and no percentage, because the price did not change.
     *
     * A scheduled change on a cancelled subscription is left out too: it will
     * never be charged.
     *
     * @param list<Subscription> $subscriptions
     * @return list<HouseholdChange>
     */
    public function householdChanges(Scope $scope, array $subscriptions): array
    {
        $today = $this->clock->today();
        $history = $this->historyBySubscription($scope);

        $rows = [];
        foreach ($subscriptions as $subscription) {
            $previous = null;

            foreach ($history[$subscription->id] ?? [] as $change) {
                $before = $previous;
                $previous = $change;

                if ($before === null || $change->source === PriceChangeSource::TrialConversion) {
                    continue;
                }

                $isScheduled = $change->isScheduled($today);
                if ($isScheduled && $subscription->isCancelled()) {
                    continue;
                }

                $converted = $change->isConversionFrom($before);
                $difference = $converted ? null : $change->differenceFrom($before);
                $cycle = $subscription->billingCycle;

                $rows[] = [
                    'subscription' => $subscription,
                    'change' => $change,
                    'from' => $before->price,
                    'to' => $change->price,
                    'kind' => match (true) {
                        $converted => 'converted',
                        $difference > 0 => 'rise',
                        $difference < 0 => 'cut',
                        default => 'unchanged',
                    },
                    'is_rise' => $change->isRiseFrom($before),
                    'ends_offer' => $change->endsOfferFrom($before),
                    'is_scheduled' => $isScheduled,
                    'difference_minor' => $difference,
                    // In tenths of a percent, so "+6.3%" is exact integer
                    // arithmetic until the formatter prints it.
                    'percent_tenths' => $difference !== null && $before->price->amountMinor > 0
                        ? Rounding::multiplyDivide($difference, 1000, $before->price->amountMinor)
                        : null,
                    // At the subscription's own cycle, as the insight rules
                    // state a rise: £2 a month is £24 a year. None for a
                    // one-off or a lifetime purchase, which has no year.
                    'annual_minor' => $difference !== null
                        && $cycle !== null
                        && $subscription->type->countsTowardsRecurringTotals()
                            ? $cycle->annualMinor($difference, $subscription->cycleDays, $subscription->cycleInterval)
                            : null,
                ];
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => $b['change']->effectiveFrom <=> $a['change']->effectiveFrom
                ?: $b['change']->id <=> $a['change']->id,
        );

        return $rows;
    }

    /**
     * Record the price a subscription was created with.
     *
     * Dated from the subscription's start date when it has one, so that a
     * subscription entered today but running since last year does not appear to
     * have sprung into existence at today's price.
     */
    public function recordInitialPrice(
        Scope $scope,
        int $subscriptionId,
        Money $price,
        ?DateTimeImmutable $startDate,
        int $ownerUserId,
        ?PromoOffer $offer = null,
    ): void {
        $this->db->transactional(function () use (
            $scope,
            $subscriptionId,
            $price,
            $startDate,
            $ownerUserId,
            $offer,
        ): void {
            $this->history->append(
                $scope,
                $subscriptionId,
                $price,
                $startDate ?? $this->clock->today(),
                PriceChangeSource::Initial,
                null,
                $ownerUserId,
                $offer !== null,
            );

            $this->announceOfferEnd($scope, $subscriptionId, $offer, $ownerUserId);
        });
    }

    /**
     * Record a price that applies from now.
     *
     * Called when somebody edits a subscription's price. The history row and
     * the denormalised column are written together or not at all.
     */
    public function recordCurrentPrice(
        Scope $scope,
        int $subscriptionId,
        Money $price,
        PriceChangeSource $source,
        int $ownerUserId,
        ?string $note = null,
        ?DateTimeImmutable $effectiveFrom = null,
        bool $isPromotional = false,
    ): void {
        $effective = $effectiveFrom ?? $this->clock->today();

        $this->db->transactional(function () use (
            $scope,
            $subscriptionId,
            $price,
            $source,
            $ownerUserId,
            $note,
            $effective,
            $isPromotional
        ): void {
            $this->history->append(
                $scope,
                $subscriptionId,
                $price,
                $effective,
                $source,
                $note,
                $ownerUserId,
                $isPromotional,
            );
            $this->subscriptions->setPrice($scope, $subscriptionId, $price);
        });
    }

    /**
     * Make the current price's history say what the form's offer fields say.
     *
     * Called by the subscription edit after any new price has been recorded,
     * so "the current row" is the one the member is looking at. Three things
     * can change, and each touches as little as it can:
     *
     *  - the **flag** on the current row, set or cleared — on that row only;
     *    clearing it leaves the row that follows alone, which is then an
     *    ordinary announced change;
     *  - the **end**, which is the next row: an announced one is moved, or
     *    re-priced, to what the form now says; with none, one is written;
     *  - an end **removed** while the offer stays, which withdraws the
     *    announced row the offer's end wrote.
     *
     * Nothing here reaches a row whose date has passed: the repository's
     * announced-row methods refuse one in their own `WHERE`.
     *
     * Only an offer that was already running owns the announced row after
     * it. One ticked on this save leaves a change the provider announced
     * alone and writes its own end beside it — rewriting that change would
     * lose a rise the member was told about. `$wasOnOffer` says which: the
     * caller knows, because a save that moved the price has already written
     * the new, flagged row. Null asks the current row.
     */
    public function reviseOffer(
        Scope $scope,
        int $subscriptionId,
        ?PromoOffer $offer,
        int $ownerUserId,
        ?bool $wasOnOffer = null,
    ): void {
        $today = $this->clock->today();

        $this->db->transactional(function () use (
            $scope,
            $subscriptionId,
            $offer,
            $ownerUserId,
            $today,
            $wasOnOffer,
        ): void {
            [$current, $next] = $this->currentAndNext($scope, $subscriptionId, $today);
            if ($current === null) {
                return;
            }

            $wasOnOffer ??= $current->isPromotional;
            if (!$wasOnOffer) {
                // Nothing announced belongs to this offer yet.
                $next = null;
            }

            if ($current->isPromotional !== ($offer !== null)) {
                $this->history->setPromotional($scope, $current->id, $offer !== null);
            }

            if ($offer === null) {
                // Off: the flag was the whole change.
                return;
            }

            if ($offer->hasEnd()) {
                // The offer's own end is moved to what the form says rather
                // than joined by a second one.
                /** @var DateTimeImmutable $endsOn */
                $endsOn = $offer->endsOn;
                /** @var Money $then */
                $then = $offer->then;

                if ($next === null) {
                    $this->announceOfferEnd($scope, $subscriptionId, $offer, $ownerUserId);
                } elseif (!$this->samePlan($next, $endsOn, $then)) {
                    $this->history->reviseAnnounced(
                        $scope,
                        $next->id,
                        $endsOn,
                        $then,
                        $next->note ?? self::OFFER_END_NOTE,
                        $today,
                    );
                }

                return;
            }

            // The end was there and has been taken away.
            if ($next !== null && $next->source === PriceChangeSource::Scheduled) {
                $this->history->deleteAnnounced($scope, $next->id, $today);
            }
        });
    }

    /**
     * Read the offer fields of a form, or null when the box is not ticked.
     *
     * `$startsOn` is when the promotional price itself applies: the offer
     * must end after that and after today, because an end already past is not
     * an offer, it is a price history that should be entered as one. When
     * "then costs" is left blank it falls back to `$fallback` — the
     * subscription's ordinary price, where there is one.
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    public function readOffer(
        array $input,
        string $currency,
        DateTimeImmutable $startsOn,
        ?Money $fallback,
        array &$errors,
    ): ?PromoOffer {
        if (($input['is_promotional'] ?? '0') !== '1') {
            return null;
        }

        $endsRaw = trim($this->str($input, 'offer_ends_on'));
        if ($endsRaw === '') {
            return new PromoOffer(null, null);
        }

        $endsOn = $this->date($endsRaw);
        if ($endsOn === null) {
            $errors['offer_ends_on'] = 'error.date.invalid';
        } elseif ($endsOn <= $this->clock->today() || $endsOn <= $startsOn) {
            $errors['offer_ends_on'] = 'error.offer.ends_past';
        }

        $then = null;
        $thenRaw = trim($this->str($input, 'offer_then_price'));
        if ($thenRaw === '') {
            if ($fallback !== null && $fallback->currency === $currency) {
                $then = $fallback;
            } else {
                $errors['offer_then_price'] = 'error.offer.then_required';
            }
        } else {
            try {
                $then = Money::fromUserInput($thenRaw, $currency);
                if ($then->isNegative()) {
                    $errors['offer_then_price'] = 'error.price.negative';
                }
            } catch (InvalidArgumentException) {
                $errors['offer_then_price'] = 'error.price.invalid';
            }
        }

        return new PromoOffer($endsOn, $then);
    }

    /**
     * The offer a subscription's current price is on, if it is on one.
     *
     * @return Promotion|null
     */
    public function promotionFor(Scope $scope, int $subscriptionId): ?array
    {
        return $this->promotionIn($this->historyFor($scope, $subscriptionId), $this->clock->today());
    }

    /**
     * Every current promotional price in scope, keyed by subscription id —
     * the list's chips, from the one bulk query.
     *
     * @return array<int, Promotion>
     */
    public function promotions(Scope $scope): array
    {
        $today = $this->clock->today();

        $promotions = [];
        foreach ($this->historyBySubscription($scope) as $subscriptionId => $changes) {
            $promotion = $this->promotionIn($changes, $today);
            if ($promotion !== null) {
                $promotions[$subscriptionId] = $promotion;
            }
        }

        return $promotions;
    }

    /**
     * The offer fields as the edit form fills them in: the switch and end
     * from the current row and the one after it, and "then costs" from that
     * row — or, before there is an offer, the ordinary price it would go back
     * to, which is the brief's default.
     *
     * @return array{is_promotional: string, offer_ends_on: string|null, offer_then_price: string|null}
     */
    public function offerFormValues(Scope $scope, int $subscriptionId): array
    {
        $promotion = $this->promotionFor($scope, $subscriptionId);
        $endsOn = $promotion['ends_on'] ?? null;
        $then = $promotion['then'] ?? $this->ordinaryPrice($scope, $subscriptionId);

        return [
            'is_promotional' => $promotion === null ? '0' : '1',
            'offer_ends_on' => $endsOn?->format('Y-m-d'),
            'offer_then_price' => $then?->toDecimalString(),
        ];
    }

    /**
     * Whether a change is already announced after `$after` and by `$until` —
     * the window a new offer's end would fall in.
     *
     * The offer ends where the next row takes effect, so an offer ending on
     * 1 Dec with a rise already announced for 1 Nov would in fact end on
     * 1 Nov. That is refused rather than resolved by rewriting either row:
     * the announced change may be the provider's, and its alert has gone.
     */
    public function hasAnnouncedBetween(
        Scope $scope,
        int $subscriptionId,
        DateTimeImmutable $after,
        DateTimeImmutable $until,
    ): bool {
        foreach ($this->historyFor($scope, $subscriptionId) as $change) {
            if ($change->effectiveFrom > $after && $change->effectiveFrom <= $until) {
                return true;
            }
        }

        return false;
    }

    /**
     * The price an offer goes back to when the form leaves "then costs"
     * blank: the latest recorded price that was not itself promotional.
     */
    public function ordinaryPrice(Scope $scope, int $subscriptionId): ?Money
    {
        $today = $this->clock->today();

        $ordinary = null;
        foreach ($this->historyFor($scope, $subscriptionId) as $change) {
            if (!$change->isPromotional && $change->hasTakenEffectOn($today)) {
                $ordinary = $change->price;
            }
        }

        return $ordinary;
    }

    /**
     * Schedule a price change for a future date.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function schedule(Scope $scope, int $subscriptionId, array $input): void
    {
        // findForWrite, not find. A scheduled row does not look like a change
        // to the subscription, but it becomes one the moment its date arrives,
        // so it is a write and has to be gated as one. find() is widened for
        // split participants; gating on it would let somebody named on a split
        // reprice a subscription they are only entitled to look at.
        $subscription = $this->subscriptions->findForWrite($scope, $subscriptionId);
        if ($subscription === null) {
            throw new ValidationException(['subscription' => 'error.subscription.not_found']);
        }

        $errors = [];
        $today = $this->clock->today();

        $currency = Currency::normalise($this->str($input, 'currency'));
        if (!Currency::isValidCode($currency)) {
            // Default to the subscription's own currency: a scheduled change is
            // almost always "the same thing costs more", not a re-denomination.
            $currency = $subscription->price->currency;
        }

        $price = null;
        try {
            $price = Money::fromUserInput($this->str($input, 'price'), $currency);
            if ($price->isNegative()) {
                $errors['price'] = 'error.price.negative';
            }
        } catch (InvalidArgumentException) {
            $errors['price'] = 'error.price.invalid';
        }

        $effectiveFrom = $this->date($this->str($input, 'effective_from'));
        if ($effectiveFrom === null) {
            $errors['effective_from'] = 'error.price_change.date_required';
        } elseif ($effectiveFrom <= $today) {
            // A change dated today or earlier is not a schedule, it is an edit.
            // Refusing here keeps the two paths distinguishable rather than
            // letting a "scheduled" row silently become the current price.
            $errors['effective_from'] = 'error.price_change.date_past';
        }

        $note = trim($this->str($input, 'note'));
        if (mb_strlen($note) > 255) {
            $errors['note'] = 'error.note.too_long_255';
        }

        // The current price is what the offer goes back to unless the member
        // says otherwise — when it is not itself an intro price.
        $offer = $this->readOffer(
            $input,
            $currency,
            $effectiveFrom ?? $today,
            $this->ordinaryPrice($scope, $subscriptionId),
            $errors,
        );

        if (
            $offer?->endsOn !== null
            && $effectiveFrom !== null
            && !isset($errors['offer_ends_on'])
            && $this->hasAnnouncedBetween($scope, $subscription->id, $effectiveFrom, $offer->endsOn)
        ) {
            $errors['offer_ends_on'] = 'error.offer.change_announced';
        }

        if ($errors !== [] || $price === null || $effectiveFrom === null) {
            throw new ValidationException($errors);
        }

        $this->db->transactional(function () use ($scope, $subscription, $price, $effectiveFrom, $note, $offer): void {
            $this->history->append(
                $scope,
                $subscription->id,
                $price,
                $effectiveFrom,
                PriceChangeSource::Scheduled,
                $note === '' ? null : $note,
                $subscription->ownerUserId,
                $offer !== null,
            );

            $this->announceOfferEnd($scope, $subscription->id, $offer, $subscription->ownerUserId);
        });
    }

    /**
     * Bring the denormalised price on each subscription back in line with its
     * history.
     *
     * This is what makes a scheduled change take effect. It is idempotent and
     * self-healing: it compares before it writes, so on virtually every call it
     * finds nothing and does nothing.
     *
     * It must run *before* payment dates are advanced. A payment rolled forward
     * first would have been rolled at the old price, and the forecast built
     * from it would be wrong by exactly the increase the user scheduled.
     *
     * @return int Number of subscriptions whose price moved.
     *
     * @phpstan-impure
     */
    public function applyDueChanges(Scope $scope): int
    {
        if (!$scope->canWrite()) {
            // A Viewer's page load must not perform writes. The prices catch up
            // the next time somebody who can write looks at them.
            return 0;
        }

        $divergent = $this->history->findDivergentCurrentPrices($scope, $this->clock->today());
        if ($divergent === []) {
            return 0;
        }

        $applied = 0;
        foreach ($divergent as $row) {
            $this->subscriptions->setPrice(
                $scope,
                $row['subscription_id'],
                Money::of($row['price_minor'], $row['currency']),
            );
            $applied++;
        }

        return $applied;
    }

    /**
     * The trend for one subscription: each recorded price with the step from
     * the one before it, and whether it has happened yet.
     *
     * @return list<array{
     *     change: PriceChange,
     *     difference_minor: int|null,
     *     is_scheduled: bool,
     *     ends_offer: bool,
     *     show_note: bool
     * }>
     */
    public function trendFor(Scope $scope, int $subscriptionId): array
    {
        $today = $this->clock->today();
        $changes = $this->historyFor($scope, $subscriptionId);

        $trend = [];
        $previous = null;
        foreach ($changes as $change) {
            $trend[] = [
                'change' => $change,
                'difference_minor' => $change->differenceFrom($previous),
                'is_scheduled' => $change->isScheduled($today),
                'ends_offer' => $change->endsOfferFrom($previous),
                // The end row's own note says what the label beside it
                // already does; a note somebody wrote stays.
                'show_note' => $change->note !== null && $change->note !== self::OFFER_END_NOTE,
            ];
            $previous = $change;
        }

        return $trend;
    }

    /**
     * Write the announced row an offer's end becomes: the "then" price, from
     * the end date. Nothing when the end is not known.
     */
    private function announceOfferEnd(Scope $scope, int $subscriptionId, ?PromoOffer $offer, int $ownerUserId): void
    {
        if ($offer === null || !$offer->hasEnd()) {
            return;
        }

        /** @var DateTimeImmutable $endsOn */
        $endsOn = $offer->endsOn;
        /** @var Money $then */
        $then = $offer->then;

        $this->history->append(
            $scope,
            $subscriptionId,
            $then,
            $endsOn,
            PriceChangeSource::Scheduled,
            self::OFFER_END_NOTE,
            $ownerUserId,
        );
    }

    /**
     * The row in force today and the first announced one after it.
     *
     * @return array{0: PriceChange|null, 1: PriceChange|null}
     */
    private function currentAndNext(Scope $scope, int $subscriptionId, DateTimeImmutable $today): array
    {
        $current = null;
        $next = null;
        foreach ($this->historyFor($scope, $subscriptionId) as $change) {
            if ($change->hasTakenEffectOn($today)) {
                $current = $change;
            } else {
                $next ??= $change;
            }
        }

        return [$current, $next];
    }

    /**
     * @param list<PriceChange> $changes Oldest first.
     * @return Promotion|null
     */
    private function promotionIn(array $changes, DateTimeImmutable $today): ?array
    {
        $current = null;
        $next = null;
        foreach ($changes as $change) {
            if ($change->hasTakenEffectOn($today)) {
                $current = $change;
            } else {
                $next ??= $change;
            }
        }

        if ($current === null || !$current->isPromotional) {
            return null;
        }

        return [
            'change' => $current,
            'ends_on' => $next?->effectiveFrom,
            'then' => $next?->price,
        ];
    }

    private function samePlan(PriceChange $row, DateTimeImmutable $date, Money $price): bool
    {
        return $row->effectiveFrom->format('Y-m-d') === $date->format('Y-m-d')
            && $row->price->amountMinor === $price->amountMinor
            && $row->price->currency === $price->currency;
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

        return $date === false ? null : $date;
    }
}
