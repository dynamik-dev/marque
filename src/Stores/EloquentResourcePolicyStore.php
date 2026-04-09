<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Stores;

use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\DTOs\Condition;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\Models\ResourcePolicy;
use Illuminate\Support\Collection;

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
                $conditions = $this->hydrateConditions($row->conditions ?? []);

                $source = $row->resource_id !== null
                    ? "resource:{$type}:{$row->resource_id}"
                    : "resource:{$type}";

                return new PolicyStatement(
                    effect: $row->getEffectEnum(),
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

        ResourcePolicy::query()->create([
            'resource_type' => $resourceType,
            'resource_id' => $idString,
            'effect' => $statement->effect->name,
            'action' => $statement->action,
            'principal_pattern' => $statement->principalPattern,
            'conditions' => $this->serializeConditions($statement->conditions),
        ]);
    }

    public function detach(string $resourceType, string|int|null $resourceId, string $action): void
    {
        $idString = $resourceId !== null ? (string) $resourceId : null;

        ResourcePolicy::query()
            ->where('resource_type', $resourceType)
            ->where('action', $action)
            ->when(
                $idString === null,
                static fn ($query) => $query->whereNull('resource_id'),
                static fn ($query) => $query->where('resource_id', $idString),
            )
            ->delete();
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return array<int, Condition>
     */
    private function hydrateConditions(array $raw): array
    {
        return array_map(
            static fn (array $item) => new Condition(
                type: $item['type'],
                parameters: $item['parameters'] ?? [],
            ),
            array_filter($raw, 'is_array'),
        );
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
