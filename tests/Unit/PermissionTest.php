<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\IsolationMode;
use App\Domain\Permission;
use App\Domain\Role;
use App\Security\PermissionService;
use App\Security\Scope;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\TestTranslator;

/**
 * The role table, asserted directly. The matching functional test proves the
 * same rules are actually applied to a real request.
 */
final class PermissionTest extends TestCase
{
    private PermissionService $permissions;

    protected function setUp(): void
    {
        $this->permissions = new PermissionService(TestTranslator::create());
    }

    public function testViewerMayReadButNotWriteAnything(): void
    {
        $viewer = $this->scope(Role::Viewer);

        self::assertTrue($this->permissions->allows($viewer, Permission::ViewSubscriptions));

        foreach (
            [
            Permission::CreateSubscription,
            Permission::UpdateSubscription,
            Permission::DeleteSubscription,
            Permission::ManageCategories,
            Permission::ManageTags,
            Permission::ManageHousehold,
            Permission::ManageInstance,
            ] as $permission
        ) {
            self::assertFalse(
                $this->permissions->allows($viewer, $permission),
                sprintf('A viewer must not be granted %s', $permission->value),
            );
        }
    }

    public function testEditorMayWriteDataButNotAdministerTheHousehold(): void
    {
        $editor = $this->scope(Role::Editor);

        self::assertTrue($this->permissions->allows($editor, Permission::CreateSubscription));
        self::assertTrue($this->permissions->allows($editor, Permission::UpdateSubscription));
        self::assertTrue($this->permissions->allows($editor, Permission::DeleteSubscription));
        self::assertTrue($this->permissions->allows($editor, Permission::ManageCategories));

        self::assertFalse($this->permissions->allows($editor, Permission::ManageHousehold));
        self::assertFalse($this->permissions->allows($editor, Permission::ManageInstance));
    }

    public function testOwnerMayAdministerTheHouseholdButNotTheInstance(): void
    {
        $owner = $this->scope(Role::OwnerAdmin);

        self::assertTrue($this->permissions->allows($owner, Permission::ManageHousehold));
        self::assertFalse($this->permissions->allows($owner, Permission::ManageInstance));
    }

    public function testInstanceAdminIsNotAHouseholdDataSuperuser(): void
    {
        // The flag grants instance administration and nothing else. An admin
        // who is not a member of a household can neither read nor write its
        // data — which is the behaviour the scoping layer relies on.
        $admin = Scope::withoutHousehold(1, true, IsolationMode::Shared);

        self::assertTrue($this->permissions->allows($admin, Permission::ManageInstance));
        self::assertFalse($this->permissions->allows($admin, Permission::ViewSubscriptions));
        self::assertFalse($this->permissions->allows($admin, Permission::CreateSubscription));
        self::assertFalse($this->permissions->allows($admin, Permission::ManageHousehold));
        self::assertFalse($admin->hasHousehold());
    }

    public function testAnInstanceAdminWhoIsAlsoAViewerGainsNoWriteAccess(): void
    {
        $scope = Scope::forMember(1, true, 10, Role::Viewer, IsolationMode::Shared);

        self::assertTrue($this->permissions->allows($scope, Permission::ManageInstance));
        self::assertFalse($this->permissions->allows($scope, Permission::CreateSubscription));
    }

    private function scope(Role $role, bool $isInstanceAdmin = false): Scope
    {
        return Scope::forMember(1, $isInstanceAdmin, 10, $role, IsolationMode::Shared);
    }
}
