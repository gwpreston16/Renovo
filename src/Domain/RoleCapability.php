<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of the members screen's "What each role can do" table.
 *
 * Deliberately not `Permission`. That enum is the vocabulary the routes assert
 * in, and it is the wrong size for a reader: nobody comparing roles wants
 * `attachment.manage` and `price.manage` as two rows when they are one idea.
 * So each row is a plain-language capability that *names* the permissions it
 * stands for, and `RoleMatrix` answers it by putting every one of them to
 * `PermissionService`. The grouping is a presentation decision; what is in
 * each group is not, and a row whose permissions disagree for some role is
 * refused rather than rounded to one answer.
 *
 * The fence is the other half. A permission says *whether* a role may do a
 * thing; the scoping layer says *whose* rows it may do it to. A row that
 * touches rows says which of the two scoping questions reaches it — reads or
 * writes — so "Own only" is the scoping layer's answer and not a word chosen
 * here.
 */
enum RoleCapability: string
{
    case ViewSubscriptions = 'view_subscriptions';
    case AddSubscriptions = 'add_subscriptions';
    case EditMoney = 'edit_money';
    case ManageBudgets = 'manage_budgets';
    case ManageShared = 'manage_shared';
    case ImportAndBulkEdit = 'import_bulk_edit';
    case ManageHousehold = 'manage_household';

    /**
     * Every permission this row stands for. A role holds the row only if it
     * holds all of them, and `RoleMatrix` refuses a role holding some.
     *
     * @return non-empty-list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::ViewSubscriptions => [Permission::ViewSubscriptions],
            self::AddSubscriptions => [Permission::CreateSubscription],
            self::EditMoney => [
                Permission::ManagePrices,
                Permission::ManageCharges,
                Permission::ManageSplits,
                Permission::ManageAttachments,
            ],
            self::ManageBudgets => [Permission::ManageBudgets],
            self::ManageShared => [Permission::ManageCategories, Permission::ManageTags],
            self::ImportAndBulkEdit => [Permission::ImportData, Permission::BulkEdit],
            self::ManageHousehold => [Permission::ManageHousehold, Permission::ManageBackups],
        };
    }

    /**
     * Which scoping question narrows this row to a member's own rows, if any.
     *
     * Adding is unfenced: a new row is always its creator's, so "own only"
     * would be true of everybody and say nothing. Categories, tags, imports
     * and the household itself are not rows anybody owns.
     */
    public function fence(): CapabilityFence
    {
        return match ($this) {
            self::ViewSubscriptions => CapabilityFence::Reads,
            self::EditMoney, self::ManageBudgets => CapabilityFence::Writes,
            default => CapabilityFence::None,
        };
    }

    public function labelKey(): string
    {
        return 'capability.' . $this->value;
    }
}
