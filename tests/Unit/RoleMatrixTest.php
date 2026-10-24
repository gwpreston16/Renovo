<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\CapabilityFence;
use App\Domain\CapabilityGrant;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\RoleCapability;
use App\Security\PermissionService;
use App\Security\RoleMatrix;
use App\Security\Scope;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\TestTranslator;

/**
 * The members screen's "What each role can do", pinned.
 *
 * Two assertions of different kinds. The first says every cell *is*
 * `PermissionService`'s answer and the scoping layer's, recomputed here from
 * those two and nothing else, for every role, row and mode — so the table can
 * only ever say what the server enforces. The second writes the table out by
 * hand, so that a change to either one — a permission handed to a new role, a
 * fence moved — fails here and is a decision somebody makes on purpose rather
 * than a sentence on a screen that quietly changed.
 */
final class RoleMatrixTest extends TestCase
{
    private RoleMatrix $matrix;
    private PermissionService $permissions;

    protected function setUp(): void
    {
        $this->permissions = new PermissionService(TestTranslator::create());
        $this->matrix = new RoleMatrix($this->permissions);
    }

    public function testEveryCellIsWhatThePermissionsAndTheScopeSay(): void
    {
        foreach (IsolationMode::cases() as $mode) {
            foreach (RoleCapability::cases() as $capability) {
                foreach (Role::assignable() as $role) {
                    $scope = Scope::forMember(1, false, 1, $role, $mode);

                    // Every permission behind a row agrees for every role:
                    // a row that held for some of them would be a claim about
                    // part of what it names.
                    $answers = array_map(
                        fn ($permission): bool => $this->permissions->allows($scope, $permission),
                        $capability->permissions(),
                    );
                    self::assertCount(1, array_unique($answers), sprintf(
                        '%s disagrees with itself for %s.',
                        $capability->value,
                        $role->value,
                    ));

                    $fenced = match ($capability->fence()) {
                        CapabilityFence::None => false,
                        CapabilityFence::Reads => $scope->restrictsReadsToOwner(),
                        CapabilityFence::Writes => $scope->restrictsWritesToOwner(),
                    };

                    $expected = !$answers[0]
                        ? CapabilityGrant::No
                        : ($fenced ? CapabilityGrant::OwnOnly : CapabilityGrant::Yes);

                    self::assertSame(
                        $expected,
                        $this->matrix->grant($role, $mode, $capability),
                        sprintf('%s / %s / %s', $capability->value, $role->value, $mode->value),
                    );
                }
            }
        }
    }

    /**
     * @return array<string, array{IsolationMode, array<string, list<string>>}>
     */
    public static function tables(): array
    {
        // Columns: Owner/Admin, Editor, Contributor, Viewer.
        return [
            'shared' => [IsolationMode::Shared, [
                'view_subscriptions' => ['yes', 'yes', 'yes', 'yes'],
                'add_subscriptions' => ['yes', 'yes', 'yes', 'no'],
                'edit_money' => ['yes', 'yes', 'own_only', 'no'],
                'manage_budgets' => ['yes', 'yes', 'own_only', 'no'],
                'manage_shared' => ['yes', 'yes', 'no', 'no'],
                'import_bulk_edit' => ['yes', 'yes', 'no', 'no'],
                'manage_household' => ['yes', 'no', 'no', 'no'],
            ]],
            // Isolation fences every role's rows, an Owner's included.
            'isolated' => [IsolationMode::Isolated, [
                'view_subscriptions' => ['own_only', 'own_only', 'own_only', 'own_only'],
                'add_subscriptions' => ['yes', 'yes', 'yes', 'no'],
                'edit_money' => ['own_only', 'own_only', 'own_only', 'no'],
                'manage_budgets' => ['own_only', 'own_only', 'own_only', 'no'],
                'manage_shared' => ['yes', 'yes', 'no', 'no'],
                'import_bulk_edit' => ['yes', 'yes', 'no', 'no'],
                'manage_household' => ['yes', 'no', 'no', 'no'],
            ]],
        ];
    }

    /**
     * @dataProvider tables
     * @param array<string, list<string>> $expected
     */
    public function testTheTableSaysWhatItSays(IsolationMode $mode, array $expected): void
    {
        $actual = [];
        foreach ($this->matrix->rows($mode) as $row) {
            $actual[$row['capability']->value] = array_map(
                static fn (array $cell): string => $cell['grant']->value,
                $row['cells'],
            );
        }

        self::assertSame($expected, $actual);
    }

    public function testTheColumnsAreEveryAssignableRoleInOrder(): void
    {
        foreach ($this->matrix->rows(IsolationMode::Shared) as $row) {
            self::assertSame(
                Role::assignable(),
                array_map(static fn (array $cell): Role => $cell['role'], $row['cells']),
            );
        }
    }
}
