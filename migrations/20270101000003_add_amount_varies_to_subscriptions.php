<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Whether a subscription's amount varies from bill to bill — gas, electricity,
 * water — so that its price is an estimate.
 *
 * Defaulted to false, which is what every existing row already means, so there
 * is no data step and no figure moves.
 *
 * One column added; on MySQL, where DDL is not transactional, a failure leaves
 * either the column or nothing, and the manual rollback is to drop it.
 */
final class AddAmountVariesToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('amount_varies', 'boolean', ['null' => false, 'default' => false])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeColumn('amount_varies')
            ->update();
    }
}
