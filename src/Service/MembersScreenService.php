<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Household;
use App\Domain\IsolationMode;
use App\Domain\Permission;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Security\PermissionService;
use App\Security\RoleMatrix;
use App\Security\Scope;

/**
 * Everything the Members & roles screen shows, decided before it is drawn.
 *
 * One screen for every member of the household, and what differs between
 * readers is decided here rather than in the template: whether this reader
 * may manage anybody, whose figures they may see (`HouseholdOverviewService`),
 * what each role may do (`RoleMatrix`), and how long an invitation lasts
 * (`HouseholdMemberService`). The template asks none of those questions again.
 *
 * Whether a control is *drawn* is all this decides. What refuses a forged
 * request is the route's permission and, behind it, the service's own check.
 *
 * @phpstan-import-type MemberOverview from HouseholdOverviewService
 * @phpstan-import-type Row from RoleMatrix
 * @phpstan-type MemberRow array{
 *     overview: MemberOverview,
 *     manageable: bool
 * }
 * @phpstan-type Screen array{
 *     household: Household|null,
 *     rows: list<MemberRow>,
 *     may_manage: bool,
 *     any_withheld: bool,
 *     roles: list<Role>,
 *     matrix: list<Row>,
 *     isolation_mode: IsolationMode,
 *     viewer_is_instance_admin: bool,
 *     may_change_isolation: bool
 * }
 */
final class MembersScreenService
{
    public function __construct(
        private readonly HouseholdOverviewService $overview,
        private readonly HouseholdRepository $households,
        private readonly PermissionService $permissions,
        private readonly RoleMatrix $matrix,
    ) {
    }

    /**
     * @return Screen
     */
    public function screen(Scope $scope): array
    {
        $mayManage = $this->permissions->allows($scope, Permission::ManageHousehold);

        $rows = [];
        $anyWithheld = false;
        foreach ($this->overview->members($scope) as $overview) {
            $anyWithheld = $anyWithheld || $overview['figures_withheld'];

            $rows[] = [
                'overview' => $overview,
                // Never on your own row: the service refuses you your own role
                // and your own removal, so the controls would only be errors.
                'manageable' => $mayManage && !$overview['is_self'],
            ];
        }

        return [
            'household' => $this->household($scope),
            'rows' => $rows,
            'may_manage' => $mayManage,
            'any_withheld' => $anyWithheld,
            'roles' => Role::assignable(),
            'matrix' => $this->matrix->rows($scope->isolationMode),
            'isolation_mode' => $scope->isolationMode,
            // The mode is the instance's, so the way to change it is an
            // instance administrator's — shown to them and nobody else.
            'viewer_is_instance_admin' => $scope->isInstanceAdmin,
            'may_change_isolation' => $this->permissions->allows($scope, Permission::ManageInstance),
        ];
    }

    public function household(Scope $scope): ?Household
    {
        return $scope->hasHousehold() ? $this->households->findById((int) $scope->householdId) : null;
    }

    /**
     * The roles somebody may be invited as. Never Owner: promote afterwards.
     *
     * @return list<Role>
     */
    public function invitableRoles(): array
    {
        return [Role::Editor, Role::Contributor, Role::Viewer];
    }

    public function inviteLifetimeDays(): int
    {
        return HouseholdMemberService::INVITE_LIFETIME_DAYS;
    }
}
