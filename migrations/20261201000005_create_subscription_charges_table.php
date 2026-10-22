<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The payment ledger — one row per charge.
 *
 * Renovo knew what was *due*; this table remembers what was *charged*. The
 * catch-up writes a row for every billing date it rolls past, priced at what
 * was in force that day, and a member may then record what was really taken
 * or mark the charge as one that did not happen. Nothing here is read from a
 * bank: every row comes from Renovo's own billing rules or from a member.
 *
 * `expected_minor` is frozen when the row is written. A later price edit does
 * not reach back into it — the ledger says what was due then — and what was
 * really charged goes in `actual_minor` beside it, so the two can be compared.
 *
 * `household_id` and `owner_user_id` are denormalised from the subscription,
 * as they are on the price history, so the table goes through the scoping
 * layer natively rather than relying on a join to stay correct.
 *
 * The unique key on (`subscription_id`, `due_date`) is what makes the
 * catch-up idempotent: one billing date is one charge, however many times
 * somebody looks.
 *
 * On MySQL, where DDL is not transactional, a failure leaves either the table
 * or nothing; the manual rollback is to drop it.
 *
 * Rolling back drops the ledger whole: `ledger:backfill` and the catch-up can
 * write its own rows again, but an amount a member recorded, a charge they
 * skipped, a note and a payment added by hand are gone, and only the backup
 * taken before upgrading has them.
 */
final class CreateSubscriptionChargesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscription_charges', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('subscription_id', 'biginteger', ['null' => false])
            ->addColumn('household_id', 'biginteger', ['null' => false])
            ->addColumn('owner_user_id', 'biginteger', ['null' => false])
            // The billing date the charge fell on.
            ->addColumn('due_date', 'date', ['null' => false])
            // The price in force on `due_date`, as it stood when the row was
            // written.
            ->addColumn('expected_minor', 'biginteger', ['null' => false])
            ->addColumn('currency', 'char', ['limit' => 3, 'null' => false])
            // What was really charged, when a member records it.
            ->addColumn('actual_minor', 'biginteger', ['null' => true])
            // 'charged' or 'skipped' (did not happen: waived, missed, refunded
            // in full). A skipped charge counts as nothing.
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'charged'])
            // 'catch_up', 'manual', 'reconstructed', 'one_off' (a one-off or
            // lifetime entry's own charge) or 'paused' (a date a resume
            // stepped over, written skipped).
            ->addColumn('source', 'string', ['limit' => 20, 'null' => false, 'default' => 'catch_up'])
            ->addColumn('note', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('recorded_by_user_id', 'biginteger', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->addIndex(
                ['subscription_id', 'due_date'],
                ['unique' => true, 'name' => 'ux_charges_subscription_due'],
            )
            ->addIndex(['household_id', 'owner_user_id'], ['name' => 'ix_charges_scope'])
            // Spend over a window, and the calendar's earliest month.
            ->addIndex(['household_id', 'due_date'], ['name' => 'ix_charges_due'])
            ->addForeignKey('subscription_id', 'subscriptions', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_charges_subscription',
            ])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_charges_household',
            ])
            ->addForeignKey('owner_user_id', 'users', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_charges_owner',
            ])
            ->addForeignKey('recorded_by_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_charges_recorder',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('subscription_charges')->drop()->save();
    }
}
