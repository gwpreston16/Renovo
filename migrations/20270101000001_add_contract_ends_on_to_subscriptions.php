<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The day a subscription's contract ends, when it has one.
 *
 * The row keeps running after it — the date marks when the terms lapse, which
 * is when a broadband or mobile plan quietly rolls onto a dearer rate. Indexed
 * because the cancel-by list and the alert scan ask about it.
 *
 * On MySQL this is not transactional. Should it stop halfway, the manual
 * rollback is: drop the index `ix_subscriptions_contract_ends_on`, then the
 * column.
 */
final class AddContractEndsOnToSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions')
            ->addColumn('contract_ends_on', 'date', ['null' => true])
            ->addIndex(['contract_ends_on'], ['name' => 'ix_subscriptions_contract_ends_on'])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscriptions')
            ->removeIndexByName('ix_subscriptions_contract_ends_on')
            ->removeColumn('contract_ends_on')
            ->update();
    }
}
