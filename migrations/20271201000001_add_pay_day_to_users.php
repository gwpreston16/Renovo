<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A member's pay day (Phase 87): any one day they were paid.
 *
 * Budgets switched to follow pay day read their periods from it — a monthly
 * one from its day of the month, a two- or four-weekly one by stepping from
 * the whole date. Null, what every existing member has, means no pay day and
 * changes nothing: a budget follows pay day only when it is switched on.
 *
 * Rolling back drops every member's recorded pay day. Only a database backup
 * brings them back (a household archive does not carry them), and until each
 * is entered again a two- or four-weekly budget cannot be measured.
 *
 * One column added; on MySQL, where DDL is not transactional, a failure leaves
 * either the column or nothing, and the manual rollback is to drop it.
 */
final class AddPayDayToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('pay_day', 'date', ['null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->removeColumn('pay_day')
            ->update();
    }
}
