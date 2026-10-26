<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Categories belong to a household rather than to a person: they are labels,
 * not financial data, and stay visible to the household in either isolation
 * mode.
 *
 * The unique index is as strict as the column's collation: on MySQL
 * (`utf8mb4_unicode_ci`) it already treats names that differ only by case or
 * accents as one, on PostgreSQL it only stops exact duplicates, and a
 * functional index on LOWER(name) is not portable between the two. So the
 * service layer makes the check, folding case and accents the way MySQL does
 * (`NameKey`), and this index is the backstop that a race reaches; the
 * violation is mapped to the duplicate message.
 */
final class CreateCategoriesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('categories', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('household_id', 'biginteger', ['null' => false])
            ->addColumn('name', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('colour', 'string', ['limit' => 7, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->addIndex(['household_id', 'name'], ['unique' => true, 'name' => 'uq_categories_household_name'])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_categories_household',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('categories')->drop()->save();
    }
}
