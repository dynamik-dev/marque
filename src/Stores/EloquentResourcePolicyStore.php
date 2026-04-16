<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Stores;

use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\DTOs\Condition;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\Enums\Effect;
use DynamikDev\Marque\Events\ResourcePolicyAttached;
use DynamikDev\Marque\Events\ResourcePolicyDetached;
use DynamikDev\Marque\Models\ResourcePolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

class EloquentResourcePolicyStore implements ResourcePolicyStore
{
    /**
     * @return Collection<int, PolicyStatement>
     */
    public function forResource(string $type, string|int|null $id): Collection
    {
        $idString = $id !== null ? (string) $id : null;

        return ResourcePolicy::query()
            ->where('resource_type', $type)
            ->where(static function ($query) use ($idString): void {
                $query->whereNull('resource_id')
                    ->orWhere('resource_id', $idString);
            })
            ->get()
            ->map(function (ResourcePolicy $row) use ($type): PolicyStatement {
                $conditions = Condition::hydrateMany($row->conditions ?? []);

                $source = $row->resource_id !== null
                    ? "resource:{$type}:{$row->resource_id}"
                    : "resource:{$type}";

                return new PolicyStatement(
                    effect: Effect::from($row->effect),
                    action: $row->action,
                    principalPattern: $row->principal_pattern,
                    resourcePattern: null,
                    conditions: $conditions,
                    source: $source,
                );
            })
            ->values();
    }

    public function attach(string $resourceType, string|int|null $resourceId, PolicyStatement $statement): void
    {
        $idString = $resourceId !== null ? (string) $resourceId : null;

        $policy = ResourcePolicy::query()->create([
            'resource_type' => $resourceType,
            'resource_id' => $idString,
            'effect' => $statement->effect->value,
            'action' => $statement->action,
            'principal_pattern' => $statement->principalPattern,
            'conditions' => $this->serializeConditions($statement->conditions),
        ]);

        $policy->getConnection()->afterCommit(static function () use ($resourceType, $resourceId, $statement): void {
            Event::dispatch(new ResourcePolicyAttached(
                resourceType: $resourceType,
                resourceId: $resourceId,
                action: $statement->action,
            ));
        });
    }

    public function detach(string $resourceType, string|int|null $resourceId, string $action): void
    {
        $idString = $resourceId !== null ? (string) $resourceId : null;

        $deleted = ResourcePolicy::query()
            ->where('resource_type', $resourceType)
            ->where('action', $action)
            ->when(
                $idString === null,
                static fn ($query) => $query->whereNull('resource_id'),
                static fn ($query) => $query->where('resource_id', $idString),
            )
            ->delete();

        if ($deleted === 0) {
            return;
        }

        (new ResourcePolicy)->getConnection()->afterCommit(static function () use ($resourceType, $resourceId, $action): void {
            Event::dispatch(new ResourcePolicyDetached(
                resourceType: $resourceType,
                resourceId: $resourceId,
                action: $action,
            ));
        });
    }

    public function detachById(string $id): void
    {
        $policy = ResourcePolicy::query()->find($id);

        if ($policy === null) {
            return;
        }

        $resourceType = $policy->resource_type;
        $resourceId = $policy->resource_id;
        $action = $policy->action;

        $policy->delete();

        $policy->getConnection()->afterCommit(static function () use ($resourceType, $resourceId, $action): void {
            Event::dispatch(new ResourcePolicyDetached(
                resourceType: $resourceType,
                resourceId: $resourceId,
                action: $action,
            ));
        });
    }

    /**
     * @param  array<int, Condition>  $conditions
     * @return array<int, array<string, mixed>>|null
     */
    private function serializeConditions(array $conditions): ?array
    {
        if ($conditions === []) {
            return null;
        }

        return array_map(
            static fn (Condition $c) => [
                'type' => $c->type,
                'parameters' => $c->parameters,
            ],
            $conditions,
        );
    }
}
