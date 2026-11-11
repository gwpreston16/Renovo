<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Whether a budget runs from pay day to pay day (Phase 87).
 *
 * Defaulted to false, which is what every existing budget already means — the
 * calendar month or year — so there is no data step and no figure moves. The
 * two- and four-weekly periods this phase adds are always written with true.
 *
 * Rolling back leaves any two- or four-weekly budget with a period the older
 * code does not know; `BudgetRepository` reads that as monthly, so the rollback
 * is safe but those budgets' figures change. Change or delete them first to
 * avoid it.
 *
 * One column added; on MySQL, where DDL is not transactional, a failure leaves
 * either the column or nothing, and the manual rollback is to drop it.
 */
final class AddFollowsPayDayToBudgets extends AbstractMigration
{
    public function up(): void
    {
        $this->table('budgets')
            ->addColumn('follows_pay_day', 'boolean', ['null' => false, 'default' => false])
            ->update();
    }

    public function down(): void
    {
        $this->table('budgets')
            ->removeColumn('follows_pay_day')
            ->update();
    }
}
