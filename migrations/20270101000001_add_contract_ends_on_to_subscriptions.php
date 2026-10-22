<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The day a subscription's contract ends, when it has one.
 *
 * The row keeps running after it — the date marks when the terms lapse, which
 * is when a broadband or mobile plan quietly rolls onto a dearer rate. Indexed
 * within the household, as the list's date filters are, for finding a
 * household's contracts by the date; the cancel-by list and the alert scan
 * read it from rows they have already loaded.
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
            ->addIndex(['household_id', 'contract_ends_on'], ['name' => 'ix_subscriptions_contract_ends_on'])
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
