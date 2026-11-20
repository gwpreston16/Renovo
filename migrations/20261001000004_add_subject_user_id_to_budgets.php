<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Whose spending a budget measures.
 *
 * Until now a budget measured its owner's share and nothing else, so the owner
 * and the subject were one column. They separate here: the owner is whoever set
 * the budget, the subject is the member it measures, and null means the whole
 * household.
 *
 * Every existing budget is backfilled to measure its owner, which is exactly
 * what it measured before — no projection changes on upgrade. `ON DELETE
 * CASCADE` matches how a departing member's own budgets already go.
 *
 * On MySQL this is not transactional. Should it stop halfway, the manual
 * rollback is: drop the foreign key `fk_budgets_subject_user`, then the index
 * `ix_budgets_subject_user`, then the column.
 *
 * Rolling back loses what the column says (K272). `down()` drops it, so under
 * the older code every budget measures its owner again: a household-wide budget
 * (subject null) becomes one for its owner's share alone, and Alice's budget
 * for Bob becomes a budget for Alice. Migrating again does not undo it, because
 * the backfill above sets every subject to the owner. Only a backup taken
 * before the rollback brings them back (one from before this migration has
 * no such budgets to restore); otherwise set each one's Whose spending again
 * on its form. `docs/upgrading.md` says so for operators.
 */
final class AddSubjectUserIdToBudgets extends AbstractMigration
{
    public function up(): void
    {
        $this->table('budgets')
            ->addColumn('subject_user_id', 'biginteger', ['null' => true])
            ->addIndex(['subject_user_id'], ['name' => 'ix_budgets_subject_user'])
            ->addForeignKey('subject_user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_budgets_subject_user',
            ])
            ->update();

        // Idempotent: a re-run after a partial MySQL failure touches only the
        // rows the first attempt did not reach.
        $this->execute('UPDATE budgets SET subject_user_id = owner_user_id WHERE subject_user_id IS NULL');
    }

    public function down(): void
    {
        $this->table('budgets')
            ->dropForeignKey('subject_user_id', 'fk_budgets_subject_user')
            ->update();

        $this->table('budgets')
            ->removeIndexByName('ix_budgets_subject_user')
            ->removeColumn('subject_user_id')
            ->update();
    }
}
