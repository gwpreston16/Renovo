<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Every permission the application checks. Routes name one of these; the
 * PermissionService is the only place that maps them onto roles.
 */
enum Permission: string
{
    case ViewSubscriptions = 'subscription.view';
    case CreateSubscription = 'subscription.create';
    case UpdateSubscription = 'subscription.update';
    case DeleteSubscription = 'subscription.delete';

    case ManageCategories = 'category.manage';
    case ManageTags = 'tag.manage';

    // Money. Viewing budgets and forecasts is part of viewing subscriptions —
    // a figure derived from data you can already see discloses nothing new —
    // so only the writes need permissions of their own.
    case ManageBudgets = 'budget.manage';
    case ManagePrices = 'price.manage';
    // Phase 34. Recording what a charge really was, skipping one, or adding
    // one that fell off the cycle: a write to the money of a row, held with
    // the price.
    case ManageCharges = 'charge.manage';
    case ManageSplits = 'split.manage';
    case RecordUsage = 'usage.record';
    case BulkEdit = 'subscription.bulk_edit';

    // Reading the household: who is in it, what each member carries and what
    // their role lets them do. A read, but not one a Viewer gets — what it
    // discloses is every other member's spending, which is a household's
    // business rather than an onlooker's.
    case ViewHousehold = 'household.view';
    case ManageHousehold = 'household.manage';

    // Phase 5. Attaching an invoice and importing a file are both ordinary
    // writes to household data, so they sit with the other write permissions.
    // Restoring a backup is not: it rewrites the household wholesale, which is
    // a household-management act however it is dressed up.
    case ManageAttachments = 'attachment.manage';
    case ImportData = 'data.import';
    case ManageBackups = 'backup.manage';

    // Reading the audit log. Not a household-data permission: what it grants is
    // sight of who did what, which an instance administrator has instance-wide
    // and a household Owner has for their own household.
    case ViewAuditLog = 'audit.view';

    case ManageInstance = 'instance.manage';

    public function isMutating(): bool
    {
        return !in_array($this, [self::ViewSubscriptions, self::ViewHousehold, self::ViewAuditLog], true);
    }
}
