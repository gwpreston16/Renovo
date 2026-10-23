<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * What a charge would have cost without the offer it was on: the reference
 * its saving is measured against.
 *
 * Written with the charge when the price in force on its date is promotional
 * (the first ordinary price that follows), or by a member saying a lower
 * actual was a discount (the expected amount). Frozen, as `expected_minor`
 * is: a later edit to the price history does not change a past saving.
 *
 * Nullable, and null on every existing row: no charge has a reference until
 * `ledger:backfill` fills the ones on a promotional price, so no figure moves
 * on upgrade.
 *
 * One column added; on MySQL, where DDL is not transactional, a failure leaves
 * either the column or nothing, and the manual rollback is to drop it.
 * Rolling back loses every reference, and the discounts members marked with
 * them; the backfill can write the offers' again, but not the discounts.
 */
final class AddReferenceMinorToSubscriptionCharges extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscription_charges')
            ->addColumn('reference_minor', 'biginteger', ['null' => true, 'after' => 'actual_minor'])
            ->update();
    }

    public function down(): void
    {
        $this->table('subscription_charges')
            ->removeColumn('reference_minor')
            ->update();
    }
}
