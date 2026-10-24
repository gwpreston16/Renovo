<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Api\Resource;
use App\I18n\Translator;
use App\Domain\Permission;
use App\Security\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who this token is, and what it can do.
 *
 * The first call a client makes, and the one that saves it guessing. It reports
 * the effective answer — role, isolation mode and the resolved permission list
 * — rather than leaving a script to infer from a 403 that it should not have
 * tried. The permissions are computed by the same PermissionService the routes
 * assert with, so what this says and what the API allows cannot disagree. The
 * token itself is described too (Phase 42), with its scopes and expiry, so a
 * script can tell what it was issued for and when it has to be replaced.
 */
final class MeApiController extends ApiController
{
    public function __construct(
        Translator $translator,
        private readonly PermissionService $permissions,
    ) {
        parent::__construct($translator);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->user($request);
        $scope = $this->scope($request);

        $allowed = [];
        foreach (Permission::cases() as $permission) {
            if ($this->permissions->allows($scope, $permission)) {
                $allowed[] = $permission->value;
            }
        }

        return $this->json($response, ['data' => [
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'display_name' => $user->displayName,
                'is_instance_admin' => $user->isInstanceAdmin,
            ],
            'household_id' => $scope->householdId,
            'role' => $scope->role?->value,
            'isolation_mode' => $scope->isolationMode->value,
            'permissions' => $allowed,
            'token' => Resource::token($this->token($request)),
        ]]);
    }
}
