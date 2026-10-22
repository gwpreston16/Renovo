<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillingCycle;
use App\Domain\BudgetPeriod;
use App\Domain\Currency;
use App\Domain\Entity\Budget;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Repository\BudgetRepository;
use App\Repository\CategoryRepository;
use App\Repository\MembershipRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Budgets and how close they are to being blown.
 *
 * **The trigger is projected spend, not spend so far.** A budget that only
 * reacts once the money has gone is a report, not a budget. What matters is
 * that a scheduled price rise or a trial about to convert will push the member
 * over, and that they hear about it while there is still something they can do.
 *
 * **The projection comes from the forecast, deliberately, rather than from a
 * monthly run-rate.** The two would otherwise disagree: a budget computed from
 * today's prices would miss the very increases that this phase exists to track,
 * and the dashboard would show a budget comfortably under its limit directly
 * above a forecast saying otherwise. Sharing one implementation makes that
 * impossible rather than merely unlikely.
 *
 * A budget measures its **subject**: one member's share — their own
 * subscriptions in full, their portion of anything split, nothing belonging to
 * anybody else — or, with no subject, the whole household. The owner is only
 * whoever set it. Either way the figure is computed in the scope of whoever is
 * looking, so another member's "only me" subscription is never in it, and a
 * figure that scope cannot honestly compute is reported as unavailable rather
 * than as a partial total that looks like a whole one.
 *
 * Firing alerts is Phase 3. This phase computes the state and shows it.
 *
 * @phpstan-type BudgetProgress array{
 *     budget: Budget,
 *     projected: Money|null,
 *     limit: Money,
 *     percent: int|null,
 *     is_over: bool,
 *     is_warning: bool,
 *     remaining: Money|null,
 *     unconvertible: list<string>,
 *     unavailable: bool
 * }
 *
 * @phpstan-type WindowCharge array{
 *     subscription: \App\Domain\Entity\Subscription,
 *     date: DateTimeImmutable,
 *     amount: Money,
 *     reason: string
 * }
 */
final class BudgetService
{
    /** The subject value that means "the whole household". */
    public const SUBJECT_HOUSEHOLD = 'household';

    /** Where the form's warning slider starts. */
    public const DEFAULT_WARN_THRESHOLD = 85;

    private const MAX_WARN_THRESHOLD = 100;

    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly CategoryRepository $categories,
        private readonly MembershipRepository $memberships,
        private readonly ForecastService $forecast,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<Budget>
     */
    public function all(Scope $scope, bool $activeOnly = true): array
    {
        return $this->budgets->findAll($scope, $activeOnly);
    }

    public function find(Scope $scope, int $id): ?Budget
    {
        return $this->budgets->find($scope, $id);
    }

    /**
     * Every budget in scope with its projected position.
     *
     * @return list<BudgetProgress>
     */
    public function progress(Scope $scope): array
    {
        $budgets = $this->all($scope);
        if ($budgets === []) {
            return [];
        }

        // The forecast is walked once per member and horizon rather than once
        // per budget: a member with an overall budget and five category ones
        // would otherwise walk the same twelve months six times.
        $chargeCache = [];
        $progress = [];

        foreach ($budgets as $budget) {
            if (!$this->isMeasurableBy($scope, $budget)) {
                $progress[] = $this->unavailable($budget);
                continue;
            }

            $key = ($budget->subjectUserId ?? 'household') . ':' . $budget->period->months();
            $chargeCache[$key] ??= $this->withinWindow(
                $this->forecast->charges($scope, $budget->period->months(), $budget->subjectUserId),
                $budget->period->months(),
            );

            $progress[] = $this->progressFor($budget, $chargeCache[$key]);
        }

        return $progress;
    }

    /**
     * The charges inside a rolling window of `$months` from today (K2).
     *
     * Two bounds, because no single date counts every row once near a month
     * end. Each row is bounded by its own schedule: its charges from the
     * first one on or after today up to, not including, that charge
     * `$months` on by the row's own anchor — so a monthly charge due today
     * is not counted again a month later, and one billed on the 31st is
     * counted once on 31 January whatever February does. And every charge
     * must fall on or before the same day `$months` on, clamped to its month
     * (31 January plus a month is 28 February, not 3 March), so a yearly
     * renewal in July is not in a monthly budget.
     *
     * @param list<WindowCharge> $charges Oldest first, as the forecast lists them.
     * @return list<WindowCharge>
     */
    private function withinWindow(array $charges, int $months): array
    {
        $today = $this->clock->today()->setTime(0, 0);
        $month = $today->modify('first day of this month')->modify(sprintf('+%d months', $months));
        $last = $month->setDate(
            (int) $month->format('Y'),
            (int) $month->format('n'),
            min((int) $today->format('j'), (int) $month->format('t')),
        );

        $rowEnds = [];
        $kept = [];
        foreach ($charges as $charge) {
            $date = $charge['date']->setTime(0, 0);
            if ($date < $today || $date > $last) {
                continue;
            }

            $subscription = $charge['subscription'];
            $rowEnds[$subscription->id] ??= BillingCycle::Monthly->advance(
                $date,
                null,
                $subscription->anchorDay ?? (int) $date->format('j'),
                $months,
            );

            if ($date < $rowEnds[$subscription->id]) {
                $kept[] = $charge;
            }
        }

        return $kept;
    }

    /**
     * Whether this scope can see everything the budget measures.
     *
     * In ISOLATED mode a scope sees only its own rows (and splits it is in), so
     * it can measure only itself. A household budget, or one whose subject is
     * somebody else — both possible only if they were set before the instance
     * switched — would be computed from a partial view and reported as though
     * it were whole, which is worse than saying nothing.
     */
    public function isMeasurableBy(Scope $scope, Budget $budget): bool
    {
        if (!$scope->restrictsReadsToOwner()) {
            return true;
        }

        return $budget->subjectUserId === $scope->userId;
    }

    /**
     * @return BudgetProgress
     */
    private function unavailable(Budget $budget): array
    {
        return [
            'budget' => $budget,
            'projected' => null,
            'limit' => $budget->amount,
            'percent' => null,
            'is_over' => false,
            'is_warning' => false,
            'remaining' => null,
            'unconvertible' => [],
            'unavailable' => true,
        ];
    }

    /**
     * One budget's position.
     *
     * @param list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money,
     *                   reason: string}> $charges
     * @return BudgetProgress
     */
    public function progressFor(Budget $budget, array $charges): array
    {
        $byCurrency = [];
        foreach ($charges as $charge) {
            if ($budget->categoryId !== null && $charge['subscription']->categoryId !== $budget->categoryId) {
                continue;
            }

            $currency = $charge['amount']->currency;
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $charge['amount']->amountMinor;
        }

        $target = $budget->amount->currency;
        $unconvertible = [];
        foreach (array_keys($byCurrency) as $currency) {
            if ($this->rates->rateFor((string) $currency, $target) === null) {
                $unconvertible[] = (string) $currency;
            }
        }

        if ($unconvertible !== []) {
            // Some of the spend cannot be expressed in the budget's currency,
            // so there is no honest total. Reporting the convertible part as
            // though it were the whole would show a budget under its limit
            // when it may be well over — the one failure mode worth refusing
            // outright.
            sort($unconvertible);

            return [
                'budget' => $budget,
                'projected' => null,
                'limit' => $budget->amount,
                'percent' => null,
                'is_over' => false,
                'is_warning' => false,
                'remaining' => null,
                'unconvertible' => $unconvertible,
                'unavailable' => false,
            ];
        }

        $projectedMinor = $this->rates->combine($byCurrency, $target) ?? 0;
        $projected = Money::of($projectedMinor, $target);
        $limitMinor = $budget->amount->amountMinor;

        $percent = $limitMinor > 0
            ? Rounding::multiplyDivide($projectedMinor, 100, $limitMinor)
            : null;

        $threshold = $budget->warnThresholdPercent;

        return [
            'budget' => $budget,
            'projected' => $projected,
            'limit' => $budget->amount,
            'percent' => $percent,
            'is_over' => $projectedMinor > $limitMinor,
            // A warning is only a warning while it is not yet a breach —
            // showing both at once would be noise. Compared in minor units,
            // not on the rounded percentage, so 84.5% of the limit is not yet
            // past an 85% threshold.
            'is_warning' => $threshold !== null
                && $limitMinor > 0
                && $projectedMinor * 100 >= $threshold * $limitMinor
                && $projectedMinor <= $limitMinor,
            'remaining' => Money::of($limitMinor - $projectedMinor, $target),
            'unconvertible' => [],
            'unavailable' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function create(Scope $scope, array $input): int
    {
        return $this->budgets->create($scope, $this->validate($scope, $input));
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function update(Scope $scope, int $id, array $input): void
    {
        // The form offers no currency, so an edit keeps the budget's own —
        // one set in another currency before budgets were base-currency only
        // is not quietly relabelled.
        if (!array_key_exists('currency', $input)) {
            $existing = $this->find($scope, $id);
            if ($existing !== null) {
                $input['currency'] = $existing->amount->currency;
            }
        }

        $data = $this->validate($scope, $input);

        // Editing a budget does not take it over. The owner is whoever set it,
        // and only an explicit `owner_user_id` — which the form no longer
        // sends — moves it.
        if (!array_key_exists('owner_user_id', $input)) {
            unset($data['owner_user_id']);
        }

        // Nor does a form that does not show the subject picker change whose
        // spending the budget measures.
        if (!array_key_exists('subject_user_id', $input)) {
            unset($data['subject_user_id']);
        }

        // The form has no Active toggle, so an edit leaves the flag alone
        // rather than quietly switching a budget back on.
        if (!array_key_exists('is_active', $input)) {
            unset($data['is_active']);
        }

        $this->budgets->update($scope, $id, $data);
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->budgets->delete($scope, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function validate(Scope $scope, array $input): array
    {
        $errors = [];

        $name = trim($this->str($input, 'name'));
        if ($name === '') {
            $errors['name'] = 'error.budget.name_required';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'error.name.too_long_100';
        }

        // The form offers no currency: a new budget is in the base currency,
        // and an edit keeps the one the budget has (the controller sends it).
        $currency = Currency::normalise($this->str($input, 'currency'));
        if ($currency === '') {
            $currency = $this->settings->baseCurrency();
        }
        if (!Currency::isValidCode($currency)) {
            $errors['currency'] = 'error.currency.required';
            $currency = 'GBP';
        }

        $amount = null;
        try {
            $amount = Money::fromUserInput($this->str($input, 'amount'), $currency);
            if ($amount->isNegative()) {
                $errors['amount'] = 'error.amount.negative';
            }
        } catch (InvalidArgumentException) {
            $errors['amount'] = 'error.amount.invalid';
        }

        $period = BudgetPeriod::tryFromString($this->str($input, 'period'));
        if ($period === null) {
            $errors['period'] = 'error.budget.period';
        }

        $categoryId = $this->positiveInt($input['category_id'] ?? null);
        if ($categoryId !== null && $this->categories->find($scope, $categoryId) === null) {
            $errors['category_id'] = 'error.category.not_found';
        }

        $thresholdRaw = trim($this->str($input, 'warn_threshold_percent'));
        $threshold = $thresholdRaw === '' ? null : (int) $thresholdRaw;
        if ($threshold !== null && ($threshold < 1 || $threshold > self::MAX_WARN_THRESHOLD)) {
            $errors['warn_threshold_percent'] = 'error.budget.threshold_range';
        }

        $ownerUserId = $this->resolveOwner($scope, $input, $errors);
        $subjectUserId = $this->resolveSubject($scope, $input, $ownerUserId, $errors);

        if ($errors !== [] || $amount === null || $period === null) {
            throw new ValidationException($errors);
        }

        return [
            'name' => $name,
            'category_id' => $categoryId,
            'period' => $period->value,
            'amount_minor' => $amount->amountMinor,
            'currency' => $currency,
            'warn_threshold_percent' => $threshold,
            'is_active' => ($input['is_active'] ?? '1') !== '0',
            'owner_user_id' => $ownerUserId,
            'subject_user_id' => $subjectUserId,
        ];
    }

    /**
     * Whose spending the budget measures: a member id, or null for the whole
     * household. Empty means the owner, which is what every budget measured
     * before there was a choice.
     *
     *  - **The household** only in SHARED mode, where the setter can see the
     *    whole of it, and only for a role that answers for the household — a
     *    Contributor's writes are fenced to their own rows, and the budget is
     *    one of them.
     *  - **Another member** only for a scope not fenced to its own rows: an
     *    Owner/Admin or Editor in SHARED mode. In ISOLATED nobody can see
     *    another member's rows, whatever their role, so nobody measures them.
     *  - **Yourself**, always.
     *
     * @param array<string, mixed>  $input
     * @param array<string, string> $errors
     */
    private function resolveSubject(Scope $scope, array $input, int $ownerUserId, array &$errors): ?int
    {
        $raw = trim($this->str($input, 'subject_user_id'));

        if ($raw === self::SUBJECT_HOUSEHOLD) {
            if ($scope->restrictsReadsToOwner()) {
                $errors['subject_user_id'] = 'error.budget.household_isolated';
            } elseif ($scope->restrictsWritesToOwner()) {
                $errors['subject_user_id'] = 'error.budget.subject_self_only';
            }

            return null;
        }

        $requested = $this->positiveInt($raw) ?? $ownerUserId;
        if ($requested === $scope->userId) {
            return $requested;
        }

        if ($scope->restrictsWritesToOwner()) {
            $errors['subject_user_id'] = 'error.budget.subject_self_only';

            return $scope->userId;
        }

        if (!in_array($requested, $this->memberIds($scope), true)) {
            $errors['subject_user_id'] = 'error.member.not_in_household';

            return $scope->userId;
        }

        return $requested;
    }

    /**
     * Whose spending this scope may choose to budget, by the rules
     * `resolveSubject()` enforces: the whole household only in SHARED mode and
     * for a role not fenced to its own rows; every member for that same role;
     * otherwise only the viewer.
     *
     * @return array{household: bool, members: list<array{id: int, display_name: string}>}
     */
    public function subjectOptions(Scope $scope): array
    {
        $members = $scope->hasHousehold()
            ? $this->memberships->findMembersOfHousehold((int) $scope->householdId)
            : [];

        if ($scope->restrictsWritesToOwner()) {
            $members = array_values(array_filter(
                $members,
                static fn (array $member): bool => $member['id'] === $scope->userId,
            ));
        }

        return [
            'household' => !$scope->restrictsReadsToOwner() && !$scope->restrictsWritesToOwner(),
            'members' => array_map(
                static fn (array $member): array => [
                    'id' => (int) $member['id'],
                    'display_name' => (string) $member['display_name'],
                ],
                $members,
            ),
        ];
    }

    /**
     * Whether a subject — a member id, or null for the household — is one this
     * scope may choose. An edit whose budget measures something else (one set
     * for the household before the instance became ISOLATED, say) must not
     * offer a picker at all: it would post one of the offered values and
     * quietly change whose spending the budget measures.
     */
    public function offersSubject(Scope $scope, ?int $subjectUserId): bool
    {
        $options = $this->subjectOptions($scope);

        return $subjectUserId === null
            ? $options['household']
            : in_array($subjectUserId, array_column($options['members'], 'id'), true);
    }

    /**
     * @return list<int>
     */
    private function memberIds(Scope $scope): array
    {
        return $scope->hasHousehold()
            ? array_map(
                static fn (array $member): int => $member['id'],
                $this->memberships->findMembersOfHousehold((int) $scope->householdId),
            )
            : [];
    }

    /**
     * Whose budget this is.
     *
     * In ISOLATED mode it can only ever be your own — a budget you could not
     * then see would be worse than useless. In SHARED mode an Owner/Admin may
     * set one up on behalf of another member, which is how a household sets
     * expectations for somebody who has not got round to it.
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function resolveOwner(Scope $scope, array $input, array &$errors): int
    {
        if ($scope->restrictsWritesToOwner()) {
            return $scope->userId;
        }

        $requested = $this->positiveInt($input['owner_user_id'] ?? null);
        if ($requested === null || $requested === $scope->userId) {
            return $scope->userId;
        }

        if (!in_array($requested, $this->memberIds($scope), true)) {
            $errors['owner_user_id'] = 'error.member.not_in_household';

            return $scope->userId;
        }

        return $requested;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function str(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
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
