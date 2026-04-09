<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Resolvers;

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Contracts\PolicyResolver;
use DynamikDev\Marque\Contracts\RoleStore;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\Enums\Effect;
use DynamikDev\Marque\Models\Assignment;
use Illuminate\Support\Collection;

class IdentityPolicyResolver implements PolicyResolver
{
    public function __construct(
        private readonly AssignmentStore $assignments,
        private readonly RoleStore $roles,
    ) {}

    /**
     * @return Collection<int, PolicyStatement>
     */
    public function resolve(EvaluationRequest $request): Collection
    {
        $scope = $request->context->scope;

        $assignments = $scope === null
            ? $this->assignments->forSubjectGlobal($request->principal->type, $request->principal->id)
            : $this->assignments->forSubjectGlobalAndScope($request->principal->type, $request->principal->id, $scope);

        if ($assignments->isEmpty()) {
            return collect();
        }

        /** @var array<int, string> $roleIds */
        $roleIds = $assignments->pluck('role_id')->unique()->values()->all();
        $permissionsByRole = $this->roles->permissionsForRoles($roleIds);

        $statements = collect();

        foreach ($assignments as $assignment) {
            /** @var Assignment $assignment */
            $roleId = $assignment->role_id;
            $permissions = $permissionsByRole[$roleId] ?? [];

            foreach ($permissions as $permission) {
                $isDeny = str_starts_with($permission, '!');
                $action = $isDeny ? substr($permission, 1) : $permission;
                $effect = $isDeny ? Effect::Deny : Effect::Allow;

                $statements->push(new PolicyStatement(
                    effect: $effect,
                    action: $action,
                    principalPattern: null,
                    resourcePattern: null,
                    conditions: [],
                    source: "role:{$roleId}",
                ));
            }
        }

        return $statements;
    }
}
