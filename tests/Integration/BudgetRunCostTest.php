<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BudgetPeriod;
use App\Domain\Role;
use App\Service\BudgetService;

/**
 * What a member's daily run measures (K180). The run acts only on the budgets
 * a member owns and the ones they are the subject of, so it must not pay to
 * measure anyone else's: in a big shared household that was most of its cost.
 */
final class BudgetRunCostTest extends NotificationTestCase
{
    private int $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->carol = $this->users->create('carol@example.test', 'Carol', 'hash');
        $this->memberships->create($this->household, $this->carol, Role::Editor);

        $this->createSubscription('Alice music', 1000, '2026-09-20', $this->alice);
        $this->createSubscription('Bob gym', 3000, '2026-09-22', $this->bob);
        $this->createSubscription('Carol cloud', 700, '2026-09-24', $this->carol);
    }

    public function testAMemberWhoOwnsNoBudgetDoesNotPayForOthers(): void
    {
        // Bob owns nothing and is the subject of nothing: his run has no
        // budget to act on, however many the household has.
        $this->budget($this->alice, BudgetPeriod::Monthly, (string) $this->alice);
        $one = $this->statementsForARunOf($this->bob);

        // Five more, each with a period or subject of its own, so each would
        // need a forecast walk of its own if it were measured.
        $this->budget($this->alice, BudgetPeriod::Annual, (string) $this->alice);
        $this->budget($this->alice, BudgetPeriod::Monthly, BudgetService::SUBJECT_HOUSEHOLD);
        $this->budget($this->alice, BudgetPeriod::Annual, BudgetService::SUBJECT_HOUSEHOLD);
        $this->budget($this->carol, BudgetPeriod::Monthly, (string) $this->carol);
        $this->budget($this->carol, BudgetPeriod::Annual, (string) $this->carol);
        $six = $this->statementsForARunOf($this->bob);

        self::assertSame($one, $six, 'the cost of a run does not grow with other members\' budgets');
    }

    public function testARunsBudgetsAreTheOnesItActsOnInTheOrderProgressGivesThem(): void
    {
        $own = $this->budget($this->bob, BudgetPeriod::Monthly, (string) $this->bob, '5.00');
        $aboutBob = $this->budget($this->alice, BudgetPeriod::Monthly, (string) $this->bob, '5.00');
        $others = [
            $this->budget($this->alice, BudgetPeriod::Monthly, (string) $this->alice, '5.00'),
            $this->budget($this->carol, BudgetPeriod::Annual, (string) $this->carol, '5.00'),
            $this->budget($this->alice, BudgetPeriod::Annual, BudgetService::SUBJECT_HOUSEHOLD, '5.00'),
        ];
        $ownAboutOther = $this->budget($this->bob, BudgetPeriod::Monthly, (string) $this->carol, '5.00');

        $scope = $this->scope($this->bob);
        $full = [];
        foreach ($this->budgets->progress($scope) as $row) {
            $full[$row['budget']->id] = $row;
        }
        $forRun = [];
        foreach ($this->budgets->progressForRun($scope) as $row) {
            $forRun[$row['budget']->id] = $row;
        }

        // Only the budgets the run acts on, in the order progress() lists them.
        self::assertSame(
            array_values(array_filter(
                array_keys($full),
                static fn (int $id): bool => in_array($id, [$own, $aboutBob, $ownAboutOther], true),
            )),
            array_keys($forRun),
        );
        foreach ($others as $id) {
            self::assertArrayNotHasKey($id, $forRun, 'a budget neither owned nor about the member is not measured');
        }

        // A budget the member owns is exactly what progress() gives: same
        // breach, same figure.
        foreach ([$own, $ownAboutOther] as $id) {
            self::assertEquals($full[$id], $forRun[$id]);
        }
        self::assertTrue($forRun[$own]['is_over']);

        // One that is only about them carries what the run reads: the budget,
        // its limit and that it is readable.
        self::assertSame($full[$aboutBob]['budget']->id, $forRun[$aboutBob]['budget']->id);
        self::assertEquals($full[$aboutBob]['limit'], $forRun[$aboutBob]['limit']);
        self::assertSame($full[$aboutBob]['unavailable'], $forRun[$aboutBob]['unavailable']);
    }

    public function testTheAlertsOfARunAreWhatTheyWereForOwnerAndSubject(): void
    {
        // Bob owns a budget he is over, and is the subject of Alice's, which
        // Alice's run recorded as crossed.
        $this->addChannel($this->bob);
        $this->budget($this->bob, BudgetPeriod::Monthly, (string) $this->bob, '5.00');
        $this->budget($this->alice, BudgetPeriod::Monthly, (string) $this->alice, '5.00');
        $this->budget($this->carol, BudgetPeriod::Monthly, (string) $this->carol, '5.00');

        $alerts = $this->scanner->evaluateBudgets($this->scope($this->bob));

        self::assertCount(1, $alerts, 'the owner is told of his own crossing, and of no one else\'s');
    }

    private function statementsForARunOf(int $userId): int
    {
        $before = $this->db->statementCount();
        $this->scanner->evaluateBudgets($this->scope($userId));

        return $this->db->statementCount() - $before;
    }

    private function budget(int $ownerId, BudgetPeriod $period, string $subject, string $amount = '100.00'): int
    {
        return $this->budgets->create($this->scope($ownerId), [
            'name' => 'Budget',
            'period' => $period->value,
            'amount' => $amount,
            'currency' => 'GBP',
            'subject_user_id' => $subject,
        ]);
    }
}
