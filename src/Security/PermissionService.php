<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Permission;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;

/**
 * The only place roles are turned into yes/no answers.
 *
 * Templates may call `allows()` to decide whether to render a control, but
 * that is a convenience, not access control: every mutating route also runs
 * `assert()` server-side, so hiding a button and removing the ability to use
 * it are two separate, independent things.
 */
final class PermissionService
{
    public function allows(Scope $scope, Permission $permission): bool
    {
        return match ($permission) {
            Permission::ViewSubscriptions => $scope->canRead(),

            // What any writer may do. A Contributor holds every one of these,
            // and the scoping layer is what confines them to their own rows —
            // the permission answers "may they edit a subscription", never
            // "whose". Keeping the fence out of here is what stops a Contributor
            // needing a parallel set of permissions nobody would remember to
            // keep in step.
            Permission::CreateSubscription,
            Permission::UpdateSubscription,
            Permission::DeleteSubscription,
            Permission::ManageBudgets,
            Permission::ManagePrices,
            Permission::ManageCharges,
            Permission::ManageSplits,
            Permission::RecordUsage,
            Permission::ManageAttachments,
            // Read-only, and still a writer's permission. The household screen
            // shows what every member spends, which is a thing the people who
            // take part in the household see and a Viewer — somebody given
            // sight of the subscriptions and nothing else — does not.
            Permission::ViewHousehold => $scope->canWrite(),

            // What only a writer answerable for the whole household may do.
            // None of these can be fenced to one member's own rows: a category
            // and a tag are shared by every subscription that carries them, and
            // an import and a bulk edit write across rows by definition. A
            // Contributor is therefore refused them outright rather than being
            // handed a scoped version that would not mean anything.
            Permission::ManageCategories,
            Permission::ManageTags,
            Permission::BulkEdit,
            Permission::ImportData => $scope->canManageShared(),

            // Restoring a backup replaces what the household holds, so it asks
            // for the role that may manage the household rather than the one
            // that may edit a row in it.
            Permission::ManageHousehold,
            Permission::ManageBackups => $scope->canManageHousehold(),

            // Two independent routes to the log, and the repository decides
            // which rows each one gets: instance-wide for an administrator,
            // household-scoped for an Owner. Neither implies the other.
            Permission::ViewAuditLog => $scope->isInstanceAdmin || $scope->canManageHousehold(),

            // Instance administration is the one permission the household role
            // has no say in — and equally, it grants no household data access.
            Permission::ManageInstance => $scope->isInstanceAdmin,
        };
    }

    /**
     * @throws HttpForbiddenException
     */
    public function assert(ServerRequestInterface $request, Scope $scope, Permission $permission): void
    {
        if ($this->allows($scope, $permission)) {
            return;
        }

        throw new HttpForbiddenException(
            $request,
            sprintf('Your role does not permit "%s".', $permission->value),
        );
    }
}
