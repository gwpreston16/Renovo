<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Currency;
use App\Domain\NameKey;
use App\Domain\PriceChangeSource;
use App\Domain\TagLimits;
use App\Persistence\Database;
use App\Repository\CategoryRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Security\Scope;
use App\Support\Clock;

/**
 * Changing one field across many subscriptions at once.
 *
 * Every action here is permission-checked at the route and scope-checked per
 * row. There is no bulk `UPDATE ... WHERE id IN (...)`: each row is written
 * individually through the scoping layer, so selecting a hundred ids — including
 * ids belonging to another household — changes exactly the ones the caller
 * could have changed one at a time, and the result says how many that was.
 *
 * The currency action is the one with a real decision behind it, and it is
 * worth being explicit. Changing a subscription's currency could mean
 * "re-label this amount" or "convert it". Re-labelling would turn £9.99 into
 * €9.99 silently — a 20% price change nobody asked for — so this converts at
 * today's rate, records the result in the price history like any other change,
 * and refuses outright when a rate is unavailable rather than falling back to
 * re-labelling.
 */
final class BulkActionService
{
    public const ACTION_CATEGORY = 'category';
    public const ACTION_ADD_TAG = 'add_tag';
    public const ACTION_REMOVE_TAG = 'remove_tag';
    public const ACTION_OWNER = 'owner';
    public const ACTION_PAYER = 'payer';
    public const ACTION_CURRENCY = 'currency';
    public const ACTION_ACTIVATE = 'activate';
    public const ACTION_DEACTIVATE = 'deactivate';

    /** A sane ceiling on one request. */
    private const MAX_IDS = 500;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly MembershipRepository $memberships,
        private readonly PriceHistoryService $priceHistory,
        private readonly ExchangeRateService $rates,
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly SubscriptionService $catchUp,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function actions(): array
    {
        return [
            self::ACTION_CATEGORY,
            self::ACTION_ADD_TAG,
            self::ACTION_REMOVE_TAG,
            self::ACTION_OWNER,
            self::ACTION_PAYER,
            self::ACTION_CURRENCY,
            self::ACTION_ACTIVATE,
            self::ACTION_DEACTIVATE,
        ];
    }

    /**
     * Apply one action to a selection.
     *
     * @param array<string, mixed> $input
     * @param int                  $skippedFull Set to the rows "Add tag" left alone because they
     *                                          already carry the most tags a subscription may.
     * @return int Number of subscriptions actually changed.
     * @throws ValidationException
     */
    public function apply(Scope $scope, array $input, int &$skippedFull = 0): int
    {
        $skippedFull = 0;

        $ids = $this->ids($input);
        if ($ids === []) {
            throw new ValidationException(['ids' => 'error.bulk.no_subscriptions']);
        }

        $action = is_scalar($input['action'] ?? null) ? (string) $input['action'] : '';

        return match ($action) {
            self::ACTION_CATEGORY => $this->setCategory($scope, $ids, $input),
            self::ACTION_ADD_TAG => $this->changeTag($scope, $ids, $input, true, $skippedFull),
            self::ACTION_REMOVE_TAG => $this->changeTag($scope, $ids, $input, false, $skippedFull),
            self::ACTION_OWNER => $this->setMember($scope, $ids, $input, 'owner_user_id'),
            self::ACTION_PAYER => $this->setMember($scope, $ids, $input, 'payer_user_id'),
            self::ACTION_CURRENCY => $this->convertCurrency($scope, $ids, $input),
            // Resuming skips cancelled rows: they are finished, and only an
            // explicit un-cancel (which lands on Paused) may bring one back.
            self::ACTION_ACTIVATE => $this->resume($scope, $this->subscriptions->withoutCancelled($scope, $ids)),
            self::ACTION_DEACTIVATE => $this->pause($scope, $ids),
            default => throw new ValidationException(['action' => 'error.bulk.no_action']),
        };
    }

    /**
     * Pause a selection, recording first the billing dates each has already
     * passed — as a single pause does.
     *
     * @param list<int> $ids
     */
    private function pause(Scope $scope, array $ids): int
    {
        foreach ($this->subscriptions->findManyForWrite($scope, $ids) as $subscription) {
            $this->catchUp->settlePassedDates($scope, $subscription);
        }

        return $this->subscriptions->updateMany($scope, $ids, ['is_active' => false]);
    }

    /**
     * Resume a selection, skipping the dates each one's pause covered — as a
     * single resume does — so the catch-up writes no charges for them.
     *
     * @param list<int> $ids
     */
    private function resume(Scope $scope, array $ids): int
    {
        return $this->db->transactional(function () use ($scope, $ids): int {
            $today = $this->clock->today();
            $resumable = [];
            $selected = $this->subscriptions->findManyForWrite($scope, $ids);
            foreach ($ids as $id) {
                $subscription = $selected[$id] ?? null;
                // A fixed term with no billing date left stays paused, like a
                // cancelled row (K86): the catch-up would end it again.
                if (
                    $subscription !== null
                    && !$subscription->isActive
                    && $subscription->resumeLeavesNoBillingDate($today)
                ) {
                    continue;
                }

                $resumable[] = $id;
                $resumed = $subscription !== null && !$subscription->isActive
                    ? $subscription->resumedPaymentDate($today)
                    : null;
                if ($subscription !== null && $resumed !== null) {
                    $this->subscriptions->setNextPaymentDate($scope, $id, $resumed);
                    $this->catchUp->recordPaused($scope, $subscription, $subscription->pausedDates($today));
                }
            }

            return $resumable === [] ? 0 : $this->subscriptions->updateMany($scope, $resumable, ['is_active' => true]);
        });
    }

    /**
     * @param list<int>            $ids
     * @param array<string, mixed> $input
     */
    private function setCategory(Scope $scope, array $ids, array $input): int
    {
        $categoryId = $this->positiveInt($input['category_id'] ?? null);

        // Zero or blank means "remove the category", which is a legitimate
        // bulk action and not a validation failure.
        if ($categoryId !== null && $this->categories->find($scope, $categoryId) === null) {
            throw new ValidationException(['category_id' => 'error.category.not_found']);
        }

        return $this->subscriptions->updateMany($scope, $ids, ['category_id' => $categoryId]);
    }

    /**
     * @param list<int>            $ids
     * @param array<string, mixed> $input
     */
    private function changeTag(Scope $scope, array $ids, array $input, bool $add, int &$skippedFull): int
    {
        $name = trim(is_scalar($input['tag'] ?? null) ? (string) $input['tag'] : '');
        if ($name === '') {
            throw new ValidationException(['tag' => 'error.tag.required']);
        }
        if (!TagLimits::nameFits($name)) {
            throw new ValidationException(['tag' => 'error.tag.too_long_50']);
        }

        return $this->db->transactional(function () use ($scope, $ids, $name, $add, &$skippedFull): int {
            $changed = 0;

            foreach ($ids as $id) {
                // findForWrite, so a row the caller may only look at is
                // skipped like any other row they cannot change. find() would
                // return a subscription they are a split participant in, and
                // the write below would then abort the whole transaction —
                // one unchangeable row in the selection silently undoing the
                // rest of the action.
                $subscription = $this->subscriptions->findForWrite($scope, $id);
                if ($subscription === null) {
                    continue;
                }

                $current = array_map(static fn ($tag): int => $tag->id, $subscription->tags);

                if ($add) {
                    $tagIds = $this->tags->resolveOrCreate($scope, [$name]);
                    $next = array_values(array_unique([...$current, ...$tagIds]));

                    if (count($next) > TagLimits::MAX_PER_SUBSCRIPTION) {
                        // A row the form could not save again: left alone,
                        // like one the caller cannot change, and counted.
                        $skippedFull++;
                        continue;
                    }
                } else {
                    $removing = array_map(
                        static fn ($tag): int => $tag->id,
                        array_filter(
                            $subscription->tags,
                            static fn ($tag): bool => NameKey::same($tag->name, $name),
                        ),
                    );
                    $next = array_values(array_diff($current, $removing));
                }

                if ($next === $current) {
                    continue;
                }

                // update() with no column data still re-syncs the tags, and it
                // goes through the write predicate on the way.
                $this->subscriptions->update($scope, $id, [], $next);
                $changed++;
            }

            return $changed;
        });
    }

    /**
     * @param list<int>            $ids
     * @param array<string, mixed> $input
     */
    private function setMember(Scope $scope, array $ids, array $input, string $column): int
    {
        $userId = $this->positiveInt($input['user_id'] ?? null);

        if ($column === 'owner_user_id') {
            if ($userId === null) {
                throw new ValidationException(['user_id' => 'error.member.required']);
            }

            // Handing a row to somebody else is a write outside your own rows,
            // so anybody fenced to them is refused: in ISOLATED it would give
            // away a row they can no longer see, or take one from themselves
            // without meaning to. The single-subscription form refuses it for
            // the same reason. (A Contributor never arrives here — bulk editing
            // is not theirs to do — but the question asked is still "may this
            // person write outside their own rows", so it is asked that way.)
            if ($scope->restrictsWritesToOwner()) {
                throw new ValidationException([
                    'user_id' => 'error.bulk.isolated_reassign',
                ]);
            }
        }

        if ($userId !== null && !in_array($userId, $this->memberIds($scope), true)) {
            throw new ValidationException(['user_id' => 'error.member.not_in_household']);
        }

        // A private row is its owner's and paid by its owner, so neither may be
        // handed to somebody else in bulk any more than on the form — the row
        // would vanish from the screen of the person who moved it.
        return $this->subscriptions->updateMany(
            $scope,
            $this->subscriptions->withoutPrivate($scope, $ids),
            [$column => $userId],
        );
    }

    /**
     * @param list<int>            $ids
     * @param array<string, mixed> $input
     */
    private function convertCurrency(Scope $scope, array $ids, array $input): int
    {
        $currency = Currency::normalise(is_scalar($input['currency'] ?? null) ? (string) $input['currency'] : '');
        if (!Currency::isValidCode($currency)) {
            throw new ValidationException(['currency' => 'error.currency.required']);
        }

        return $this->db->transactional(function () use ($scope, $ids, $currency): int {
            $changed = 0;

            foreach ($ids as $id) {
                // Write-scoped for the same reason as the tag action: skip what
                // the caller cannot change rather than failing the batch.
                $subscription = $this->subscriptions->findForWrite($scope, $id);
                if ($subscription === null || $subscription->price->currency === $currency) {
                    continue;
                }

                $converted = $this->rates->convert($subscription->price, $currency);
                if ($converted === null) {
                    // Refusing is the point. Re-denominating £9.99 as €9.99
                    // because no rate was available would be a silent 20% price
                    // change, and the user would have no way of knowing.
                    throw new ValidationException([
                        'currency' => new ValidationError('error.bulk.no_rate', [
                            'from' => $subscription->price->currency,
                            'to' => $currency,
                            'name' => $subscription->name,
                        ]),
                    ]);
                }

                // Recorded as a price-history row, which is what makes the
                // conversion visible and reversible: the trend shows the
                // re-denomination and says what it was.
                $this->priceHistory->recordCurrentPrice(
                    $scope,
                    $id,
                    $converted,
                    PriceChangeSource::CurrencyChange,
                    $subscription->ownerUserId,
                    sprintf(
                        'Converted from %s at %s.',
                        $subscription->price->currency,
                        $this->rates->rateFor($subscription->price->currency, $currency)?->toDecimalString() ?? '?',
                    ),
                );

                $changed++;
            }

            return $changed;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return list<int>
     */
    private function ids(array $input): array
    {
        $raw = $input['ids'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = is_scalar($value) ? (int) $value : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_slice(array_values(array_unique($ids)), 0, self::MAX_IDS);
    }

    /**
     * @return list<int>
     */
    private function memberIds(Scope $scope): array
    {
        if (!$scope->hasHousehold()) {
            return [];
        }

        return array_map(
            static fn (array $member): int => $member['id'],
            $this->memberships->findMembersOfHousehold((int) $scope->householdId),
        );
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!is_scalar($value) || (string) $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
