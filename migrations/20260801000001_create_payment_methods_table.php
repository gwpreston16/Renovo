<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * What a subscription is paid with — a card, a direct debit, PayPal.
 *
 * The same shape as `categories`, for the same reason: a payment method is a
 * label, not financial data. It belongs to the household rather than to a
 * person, has no owner column, and stays visible to the household in either
 * isolation mode. It holds no amount and no credential — a name, a colour for
 * the breakdown, and a picture.
 *
 * The picture is one of two things. `logo_path` is an image a member uploaded,
 * filed by LogoStorage exactly as a subscription's logo is. `icon` is a key
 * into the application's own icon map, which is how the seeded defaults get a
 * glyph without the project bundling anybody's trademark: "PayPal" ships with
 * a generic wallet, and a household that wants the real mark uploads it.
 *
 * The unique index is the backstop for the check the service makes, for the
 * reason given on `categories`: case and accents are folded by `NameKey`.
 */
final class CreatePaymentMethodsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('payment_methods', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('household_id', 'biginteger', ['null' => false])
            ->addColumn('name', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('colour', 'string', ['limit' => 7, 'null' => true])
            ->addColumn('icon', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('logo_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => false])
            ->addColumn('updated_at', 'timestamp', ['null' => false])
            ->addIndex(['household_id', 'name'], ['unique' => true, 'name' => 'uq_payment_methods_household_name'])
            ->addForeignKey('household_id', 'households', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_payment_methods_household',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('payment_methods')->drop()->save();
    }
}
