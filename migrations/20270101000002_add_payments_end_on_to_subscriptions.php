<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The last charge of a fixed term — a phone, a sofa, a car on finance.
 *
 * The last billing date on or before it is the final payment; the catch-up
 * ends the row the day after. Null, as every existing row is, means the row
 * runs until it is cancelled, so there is no data step and no figure moves.
 *
 * One column added; on MySQL, where DDL is not transactional, a failure leaves
 * either the column or nothing, and the manual rollback is to drop it.
 */
final class AddPaymentsEndOnToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('payments_end_on', 'date', ['null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeColumn('payments_end_on')
            ->update();
    }
}
