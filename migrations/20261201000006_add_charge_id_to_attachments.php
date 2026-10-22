<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * An invoice can belong to a payment, not only to a subscription.
 *
 * `charge_id` is nullable: most attachments stay filed against the
 * subscription alone, and an attachment outlives a charge removed beneath it
 * (`ON DELETE SET NULL`), going back to being the subscription's.
 *
 * The data step links what it can and no more: an existing attachment whose
 * `period_date` is exactly a charge's `due_date` on the same subscription is
 * that charge's invoice. A ledger that is empty at upgrade links nothing; the
 * rest stay attached to the subscription only. Portable SQL: a correlated
 * subquery rather than an UPDATE … FROM, which MySQL does not accept.
 *
 * On MySQL, where DDL is not transactional, a failure part-way leaves the
 * column without its foreign key or index; the manual rollback is to drop
 * `fk_attachments_charge`, `ix_attachments_charge` and the column, in that
 * order, whichever exist.
 */
final class AddChargeIdToAttachments extends AbstractMigration
{
    public function up(): void
    {
        $this->table('attachments')
            ->addColumn('charge_id', 'biginteger', ['null' => true])
            ->addIndex(['charge_id'], ['name' => 'ix_attachments_charge'])
            ->addForeignKey('charge_id', 'subscription_charges', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_attachments_charge',
            ])
            ->update();

        $this->execute(
            'UPDATE attachments SET charge_id = ('
            . 'SELECT MIN(c.id) FROM subscription_charges c'
            . ' WHERE c.subscription_id = attachments.subscription_id'
            . ' AND c.due_date = attachments.period_date'
            . ') WHERE charge_id IS NULL AND period_date IS NOT NULL',
        );
    }

    public function down(): void
    {
        $this->table('attachments')
            ->dropForeignKey('charge_id', 'fk_attachments_charge')
            ->removeIndexByName('ix_attachments_charge')
            ->removeColumn('charge_id')
            ->update();
    }
}
