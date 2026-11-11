<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AlertType;
use App\Domain\BudgetPeriod;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\Visibility;
use App\Security\Scope;
use App\Service\BudgetService;
use App\Service\ValidationException;

/**
 * Whose spending a budget measures, now that it is not always its owner's.
 *
 * Alice is the Owner/Admin, Bob an Editor. Alice's own subscription costs £10 a
 * month, Bob's £30, and every budget here is monthly, so the projected figure is
 * the monthly spend of whoever it measures.
 */
final class BudgetSubjectTest extends NotificationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSubscription('Alice music', 1000, '2026-09-20', $this->alice);
        $this->createSubscription('Bob gym', 3000, '2026-09-22', $this->bob);
    }

    public function testABudgetWithNoSubjectChosenMeasuresItsOwnerAsEveryBudgetDidBefore(): void
    {
        $id = $this->budget($this->scope($this->alice), []);

        $budget = $this->budgets->find($this->scope($this->alice), $id);
        self::assertNotNull($budget);
        self::assertSame($this->alice, $budget->ownerUserId);
        self::assertSame($this->alice, $budget->subjectUserId);
        self::assertSame(1000, $this->projected($this->scope($this->alice), $id));
    }

    public function testAMemberBudgetMeasuresThatMembersShareAndStaysItsSettersOwn(): void
    {
        $id = $this->budget($this->scope($this->alice), ['subject_user_id' => (string) $this->bob]);

        $budget = $this->budgets->find($this->scope($this->alice), $id);
        self::assertNotNull($budget);
        self::assertSame($this->alice, $budget->ownerUserId);
        self::assertSame($this->bob, $budget->subjectUserId);
        self::assertSame(3000, $this->projected($this->scope($this->alice), $id));
    }

    public function testAHouseholdBudgetMeasuresEverythingTheViewerCanSee(): void
    {
        // Bob keeps one to himself. It is in his reading of the household
        // budget and not in Alice's.
        $this->createSubscription('Bob private', 500, '2026-09-23', $this->bob, [
            'visibility' => Visibility::Payer->value,
        ]);

        $id = $this->budget($this->scope($this->alice), ['subject_user_id' => BudgetService::SUBJECT_HOUSEHOLD]);

        $budget = $this->budgets->find($this->scope($this->alice), $id);
        self::assertNotNull($budget);
        self::assertTrue($budget->isHousehold());
        self::assertSame(4000, $this->projected($this->scope($this->alice), $id));
        self::assertSame(4500, $this->projected($this->scope($this->bob), $id));
    }

    public function testAHouseholdBudgetIsRefusedInIsolatedMode(): void
    {
        $this->assertRefused(
            $this->scope($this->alice, IsolationMode::Isolated),
            ['subject_user_id' => BudgetService::SUBJECT_HOUSEHOLD],
            'error.budget.household_isolated',
        );
    }

    public function testNobodyMayBudgetForAnotherMemberInIsolatedModeWhateverTheirRole(): void
    {
        $this->assertRefused(
            $this->scope($this->alice, IsolationMode::Isolated),
            ['subject_user_id' => (string) $this->bob],
            'error.budget.subject_self_only',
        );
    }

    public function testAContributorMayBudgetOnlyForThemselves(): void
    {
        $contributor = Scope::forMember($this->bob, false, $this->household, Role::Contributor, IsolationMode::Shared);

        $this->assertRefused(
            $contributor,
            ['subject_user_id' => (string) $this->alice],
            'error.budget.subject_self_only',
        );
        $this->assertRefused(
            $contributor,
            ['subject_user_id' => BudgetService::SUBJECT_HOUSEHOLD],
            'error.budget.subject_self_only',
        );

        $id = $this->budget($contributor, ['subject_user_id' => (string) $this->bob]);
        self::assertSame($this->bob, $this->budgets->find($contributor, $id)?->subjectUserId);
    }

    public function testAnEditThatDoesNotShowThePickerKeepsTheSubjectAndTheOwner(): void
    {
        $id = $this->budget($this->scope($this->alice), ['subject_user_id' => (string) $this->bob]);

        $this->budgets->update($this->scope($this->bob), $id, [
            'name' => 'Renamed',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '25.00',
            'currency' => 'GBP',
        ]);

        $budget = $this->budgets->find($this->scope($this->alice), $id);
        self::assertNotNull($budget);
        self::assertSame('Renamed', $budget->name);
        self::assertSame($this->alice, $budget->ownerUserId);
        self::assertSame($this->bob, $budget->subjectUserId);
    }

    public function testAfterASwitchToIsolatedABudgetForSomebodyElseIsUnavailableToItsOwner(): void
    {
        $id = $this->budget($this->scope($this->alice), ['subject_user_id' => (string) $this->bob]);

        $aliceIsolated = $this->scope($this->alice, IsolationMode::Isolated);
        $row = $this->progressRow($aliceIsolated, $id);
        self::assertTrue($row['unavailable'], 'computed from a partial view, it would look like a whole one');
        self::assertNull($row['projected']);

        // Bob sees the budget that measures him, through the read-only
        // widening, and it is computed from his own rows.
        $bobIsolated = $this->scope($this->bob, IsolationMode::Isolated);
        self::assertSame(3000, $this->projected($bobIsolated, $id));

        // Seeing is not changing.
        $this->expectException(\App\Security\ScopeViolationException::class);
        $this->budgets->delete($bobIsolated, $id);
    }

    public function testABreachReachesTheOwnerAndTheSubjectOnceEach(): void
    {
        $this->addChannel($this->alice);
        $this->addChannel($this->bob);
        $this->budget($this->scope($this->alice), ['subject_user_id' => (string) $this->bob, 'amount' => '20.00']);

        $this->runner->run();
        $this->runner->run();

        self::assertSame([$this->alice, $this->bob], $this->budgetAlertRecipients());
    }

    public function testAHouseholdBudgetsBreachReachesOnlyItsOwner(): void
    {
        $this->addChannel($this->alice);
        $this->addChannel($this->bob);
        $this->budget($this->scope($this->alice), [
            'subject_user_id' => BudgetService::SUBJECT_HOUSEHOLD,
            'amount' => '20.00',
        ]);

        $this->runner->run();

        self::assertSame([$this->alice], $this->budgetAlertRecipients());
    }

    public function testAFortnightlyBreachNamesItsWindow(): void
    {
        $this->addChannel($this->alice);
        $this->users->updatePreferences($this->alice, ['pay_day' => '2026-09-04']);
        $this->budget($this->scope($this->alice), ['period' => BudgetPeriod::Fortnight->value, 'amount' => '5.00']);
        $this->budget($this->scope($this->alice), ['name' => 'Year', 'period' => 'annual', 'amount' => '5.00']);

        $this->runner->run();

        $lines = [];
        foreach ($this->notifier->sent as $sent) {
            if ($sent['alert']->type === AlertType::BudgetExceeded) {
                $lines[] = $sent['alert']->lines[0];
            }
        }
        sort($lines);

        self::assertSame([
            'Projected £10.00 over the next two weeks, against a limit of £5.00.',
            'Projected £120.00 over the next 12 months, against a limit of £5.00.',
        ], $lines);
    }

    public function testAWeeklyBudgetWhoseMemberClearsTheirPayDayRaisesNothing(): void
    {
        $this->addChannel($this->alice);
        $this->users->updatePreferences($this->alice, ['pay_day' => '2026-09-04']);
        $this->budget($this->scope($this->alice), ['period' => BudgetPeriod::FourWeeks->value, 'amount' => '5.00']);
        $this->users->updatePreferences($this->alice, ['pay_day' => null]);

        $this->runner->run();

        self::assertSame([], $this->budgetAlertRecipients());
    }

    /**
     * @param array<string, string> $overrides
     */
    private function budget(Scope $scope, array $overrides): int
    {
        return $this->budgets->create($scope, $overrides + [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '100.00',
            'currency' => 'GBP',
        ]);
    }

    /**
     * @param array<string, string> $input
     */
    private function assertRefused(Scope $scope, array $input, string $key): void
    {
        try {
            $this->budget($scope, $input);
        } catch (ValidationException $exception) {
            self::assertSame($key, $exception->errors()['subject_user_id']->key ?? null);

            return;
        }

        self::fail('The budget was accepted.');
    }

    private function projected(Scope $scope, int $budgetId): ?int
    {
        return $this->progressRow($scope, $budgetId)['projected']?->amountMinor;
    }

    /**
     * @return array<string, mixed>
     */
    private function progressRow(Scope $scope, int $budgetId): array
    {
        foreach ($this->budgets->progress($scope) as $row) {
            if ($row['budget']->id === $budgetId) {
                return $row;
            }
        }

        self::fail('The budget is not visible in this scope.');
    }

    /**
     * @return list<int>
     */
    private function budgetAlertRecipients(): array
    {
        $users = [];
        foreach ($this->notifier->sent as $sent) {
            if ($sent['alert']->type === AlertType::BudgetExceeded) {
                $users[] = $sent['user']->id;
            }
        }
        sort($users);

        return $users;
    }
}
