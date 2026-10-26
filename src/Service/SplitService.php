<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Allocation;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionSplit;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Domain\SplitMode;
use App\Persistence\Database;
use App\Repository\MembershipRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Security\Scope;

/**
 * Dividing a subscription's cost between household members.
 *
 * Two rules carry the whole feature.
 *
 * **Shares are derived, never stored.** The database holds integer weights; the
 * money is computed from the current price every time it is asked for. A stored
 * amount would be right on the day it was entered and wrong from the next price
 * change onwards, and nobody would notice until the shares stopped adding up.
 *
 * **The parts always sum to the whole.** Allocation hands out the leftover
 * minor units rather than rounding each share independently, so £10 split three
 * ways is 3.34 / 3.33 / 3.33 and not three times 3.33 with a penny unaccounted
 * for.
 *
 * Visibility is not this class's business. A participant's right to *see* a
 * subscription they contribute to is applied by the scoping layer's read
 * predicate, structurally, for every query at once — not by anything here
 * remembering to ask.
 */
final class SplitService
{
    public function __construct(
        private readonly SplitRepository $splits,
        private readonly SubscriptionRepository $subscriptions,
        private readonly MembershipRepository $memberships,
        private readonly Database $db,
    ) {
    }

    /**
     * @return list<SubscriptionSplit>
     */
    public function participants(Scope $scope, int $subscriptionId): array
    {
        return $this->splits->findForSubscription($scope, $subscriptionId);
    }

    /**
     * Each participant's share of the current price.
     *
     * @param list<SubscriptionSplit> $participants
     * @return list<array{user_id: int, user_name: string|null, share_units: int, amount: Money}>
     */
    public function sharesOf(Subscription $subscription, array $participants): array
    {
        if (!$subscription->splitMode->isSplit() || $participants === []) {
            return [];
        }

        $weights = array_map(
            fn (SubscriptionSplit $split): int => $this->weightOf($subscription->splitMode, $split),
            $participants,
        );

        if (array_sum($weights) <= 0) {
            return [];
        }

        $amounts = Allocation::byWeight($subscription->price->amountMinor, $weights);

        $shares = [];
        foreach ($participants as $index => $participant) {
            $shares[] = [
                'user_id' => $participant->userId,
                'user_name' => $participant->userName,
                'share_units' => $weights[$index],
                'amount' => Money::of($amounts[$index], $subscription->price->currency),
            ];
        }

        return $shares;
    }

    /**
     * What one member actually bears of a subscription's price.
     *
     * This is the figure budgets count. A member who owns a subscription but
     * has split it three ways is responsible for a third of it, and a budget
     * that charged them the whole amount would be wrong by two thirds.
     *
     * A subscription that is not split belongs entirely to its owner.
     *
     * @param list<SubscriptionSplit> $participants
     */
    public function shareFor(Subscription $subscription, array $participants, int $userId): Money
    {
        $zero = Money::zero($subscription->price->currency);

        if (!$subscription->splitMode->isSplit() || $participants === []) {
            return $subscription->ownerUserId === $userId ? $subscription->price : $zero;
        }

        foreach ($this->sharesOf($subscription, $participants) as $share) {
            if ($share['user_id'] === $userId) {
                return $share['amount'];
            }
        }

        // Split, but this member is not one of the participants: they bear
        // none of it, even if they own the row. Somebody who has handed the
        // whole cost to other members is paying nothing, and saying otherwise
        // would double-count it.
        return $zero;
    }

    /**
     * One member's part of a single charge, at whatever price it was made.
     *
     * The charge is divided the way the split divides the price: by the
     * members' weights, with the largest-remainder rule, so a member paying a
     * third still pays a third after an increase and the shares of a charge
     * add up to exactly that charge (K35). Scaling each member's current share
     * on its own could miss the charge by a minor unit each. A charge
     * reconstructed from last year is divided by today's arrangement, because
     * splits keep no history. At the current price this is the member's
     * `shareFor()`, and a free trial or £0 intro month, which has no price to
     * take a ratio of, divides its conversion charge the same way (K3).
     * Unsplit, it is the owner's whole.
     *
     * Ask `bears()` first: this answers "how much", not "whether".
     *
     * @param list<SubscriptionSplit> $participants
     */
    public function chargeShare(Money $charge, Subscription $subscription, array $participants, int $userId): Money
    {
        return $this->weightedShare($charge, $subscription, $participants, $userId);
    }

    /**
     * Whether a member bears any of a subscription's cost at all.
     *
     * Deliberately separate from `shareFor()`, and the distinction is not
     * pedantic: a free trial's current share is zero for everybody, because the
     * current price is zero. Treating "bears nothing today" as "bears nothing"
     * would drop the conversion charge — the single most important number in a
     * forecast — from every per-member projection.
     *
     * @param list<SubscriptionSplit> $participants
     */
    public function bears(Subscription $subscription, array $participants, int $userId): bool
    {
        if (!$subscription->splitMode->isSplit() || $participants === []) {
            return $subscription->ownerUserId === $userId;
        }

        foreach ($participants as $participant) {
            if ($participant->userId === $userId) {
                return $this->weightOf($subscription->splitMode, $participant) > 0;
            }
        }

        return false;
    }

    /**
     * Replace a subscription's split arrangement.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(Scope $scope, int $subscriptionId, array $input): void
    {
        // find() uses the *read* predicate, which a participant passes. The
        // check that actually matters is the next one.
        $subscription = $this->subscriptions->find($scope, $subscriptionId);
        if ($subscription === null) {
            throw new ValidationException(['subscription' => 'error.subscription.not_found']);
        }

        // Refuse a participant deliberately rather than letting the write
        // predicate throw underneath. The scoping layer would stop them either
        // way — that is what SplitVisibilityTest pins — but arriving there
        // means opening a transaction and logging a scope violation for
        // somebody who did nothing wrong, and a scope violation in the log
        // should mean something.
        if (!$this->canEdit($scope, $subscription)) {
            throw new ValidationException([
                'split_mode' => 'error.split.owner_only',
            ]);
        }

        $mode = SplitMode::tryFromString($this->str($input, 'split_mode')) ?? SplitMode::None;

        // A private subscription is paid by one person, and a split is always
        // visible to everybody in it — the two cannot coexist. The reverse,
        // making a split row private, is refused by SubscriptionService.
        if ($mode->isSplit() && $subscription->isPrivate()) {
            throw new ValidationException(['split_mode' => 'error.split.private']);
        }

        $memberIds = $this->householdMemberIds($scope);

        [$participants, $errors] = $this->validateParticipants($input, $mode, $memberIds);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->db->transactional(function () use ($scope, $subscription, $mode, $participants): void {
            // setSplitMode goes through updateScoped, which uses the write
            // predicate. A participant who is not the owner fails here, in
            // ISOLATED mode, with a ScopeViolationException — which is the
            // whole point of the read and write predicates being different.
            $this->subscriptions->setSplitMode($scope, $subscription->id, $mode);

            if ($mode->isSplit()) {
                $this->splits->replaceForSubscription(
                    $scope,
                    $subscription->id,
                    $subscription->ownerUserId,
                    $participants,
                );
            } else {
                $this->splits->deleteForSubscription($scope, $subscription->id);
            }
        });
    }

    /**
     * Whether this scope may change a subscription's split arrangement.
     *
     * Templates call it to decide whether to render the controls; it is not
     * what enforces the rule. The scoping layer's write predicate is, and it
     * applies whether or not anybody remembered to ask this.
     */
    public function canEdit(Scope $scope, Subscription $subscription): bool
    {
        if (!$scope->canWrite()) {
            return false;
        }

        // In SHARED mode the household manages its own arrangements between
        // themselves. In ISOLATED mode only the owner can, because only the
        // owner can write the row at all.
        return !$scope->restrictsWritesToOwner() || $subscription->ownerUserId === $scope->userId;
    }

    /**
     * Every split in scope, keyed by subscription id, for pages that need them
     * all at once.
     *
     * @return array<int, list<SubscriptionSplit>>
     */
    public function allInScope(Scope $scope): array
    {
        return $this->splits->findAllInScope($scope);
    }

    /**
     * The weight to use for one participant.
     *
     * An equal split ignores whatever weights happen to be stored, so that
     * switching a custom split to an equal one does the obvious thing without
     * having to rewrite every row first.
     */
    /**
     * One member's part of a charge, by the split's weights alone.
     *
     * @param list<SubscriptionSplit> $participants
     */
    private function weightedShare(Money $charge, Subscription $subscription, array $participants, int $userId): Money
    {
        if (!$subscription->splitMode->isSplit() || $participants === []) {
            return $subscription->ownerUserId === $userId ? $charge : Money::zero($charge->currency);
        }

        $weights = array_map(
            fn (SubscriptionSplit $split): int => $this->weightOf($subscription->splitMode, $split),
            $participants,
        );
        if (array_sum($weights) <= 0) {
            return Money::zero($charge->currency);
        }

        $amounts = Allocation::byWeight($charge->amountMinor, $weights);
        foreach ($participants as $index => $participant) {
            if ($participant->userId === $userId) {
                return Money::of($amounts[$index], $charge->currency);
            }
        }

        return Money::zero($charge->currency);
    }

    private function weightOf(SplitMode $mode, SubscriptionSplit $split): int
    {
        return $mode === SplitMode::Equal ? 1 : max(0, $split->shareUnits);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<int>            $memberIds
     * @return array{0: list<array{user_id: int, share_units: int}>, 1: array<string, string>}
     */
    private function validateParticipants(array $input, SplitMode $mode, array $memberIds): array
    {
        if (!$mode->isSplit()) {
            return [[], []];
        }

        $errors = [];
        $raw = $input['shares'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $participants = [];
        foreach ($raw as $userId => $units) {
            $userId = (int) $userId;
            if (!in_array($userId, $memberIds, true)) {
                // Silently skipped rather than rejected: a stale form naming a
                // member who has since left the household should not block the
                // save, and adding a stranger must not be possible either way.
                continue;
            }

            $units = $mode === SplitMode::Equal ? 1 : (int) (is_scalar($units) ? $units : 0);
            if ($units < 0 || $units > 10000) {
                $errors['shares'] = 'error.split.share_range';
                continue;
            }
            if ($units === 0) {
                // A zero share is how the form says "not a participant".
                continue;
            }

            $participants[] = ['user_id' => $userId, 'share_units' => $units];
        }

        if ($participants === [] && $errors === []) {
            $errors['shares'] = 'error.split.no_members';
        }

        return [$participants, $errors];
    }

    /**
     * @return list<int>
     */
    private function householdMemberIds(Scope $scope): array
    {
        if (!$scope->hasHousehold()) {
            return [];
        }

        return array_map(
            static fn (array $member): int => $member['id'],
            $this->memberships->findMembersOfHousehold((int) $scope->householdId),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function str(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
