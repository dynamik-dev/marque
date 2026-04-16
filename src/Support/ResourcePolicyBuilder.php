<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Support;

use Closure;
use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\DTOs\Condition;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\Enums\Effect;

/**
 * Fluent builder for attaching type-level resource policies.
 *
 * Compiles high-level expressions like "owners can update their own posts"
 * into the underlying PolicyStatement + Condition rows that the evaluator
 * consumes. Each terminal method (ownerCan, anyoneCan) attaches immediately
 * through the ResourcePolicyStore contract.
 */
class ResourcePolicyBuilder
{
    private string $ownerField = 'user_id';

    private string $subjectKey = 'id';

    /** @var array<int, Condition> */
    private array $activeConditions = [];

    public function __construct(
        private readonly ResourcePolicyStore $store,
        private readonly string $resourceType,
    ) {}

    /**
     * Declare which resource attribute identifies the owner, and which
     * principal attribute it should be compared against.
     */
    public function ownedBy(string $resourceKey, string $subjectKey = 'id'): self
    {
        $this->ownerField = $resourceKey;
        $this->subjectKey = $subjectKey;

        return $this;
    }

    /**
     * Attach allow statements gated by the configured ownership condition.
     *
     * Defaults to comparing the principal's `id` attribute to the resource's
     * `user_id` attribute. Call ownedBy() before ownerCan() to override.
     * Any conditions from an enclosing when() closure are applied in
     * addition to the ownership check.
     *
     * @param  array<int, string>|string  $actions
     */
    public function ownerCan(array|string $actions): self
    {
        $ownershipCondition = new Condition('attribute_equals', [
            'subject_key' => $this->subjectKey,
            'resource_key' => $this->ownerField,
        ]);

        $conditions = [$ownershipCondition, ...$this->activeConditions];

        foreach ($this->normalizeActions($actions) as $action) {
            $this->store->attach($this->resourceType, null, new PolicyStatement(
                effect: Effect::Allow,
                action: $action,
                conditions: $conditions,
                source: "resource-builder:{$this->resourceType}:owner",
            ));
        }

        return $this;
    }

    /**
     * Apply conditions to every allow statement emitted inside the closure.
     *
     * The condition map is translated into attribute_in conditions that
     * compare resource attributes against allowed values. Pass a scalar
     * for a single allowed value, or an array for a set. All conditions
     * from enclosing when() closures accumulate, so nesting works as
     * expected. When the closure returns, the conditions are popped and
     * subsequent chain calls are unaffected.
     *
     * @param  array<string, string|int|float|bool|array<int, string|int|float|bool>>  $conditions
     */
    public function when(array $conditions, Closure $scope): self
    {
        $newConditions = [];

        foreach ($conditions as $key => $values) {
            $newConditions[] = new Condition('attribute_in', [
                'source' => 'resource',
                'key' => $key,
                'values' => is_array($values) ? $values : [$values],
            ]);
        }

        $previousConditions = $this->activeConditions;
        $previousOwnerField = $this->ownerField;
        $previousSubjectKey = $this->subjectKey;

        $this->activeConditions = [...$previousConditions, ...$newConditions];

        try {
            $scope($this);
        } finally {
            $this->activeConditions = $previousConditions;
            $this->ownerField = $previousOwnerField;
            $this->subjectKey = $previousSubjectKey;
        }

        return $this;
    }

    /**
     * Attach allow statements inheriting the conditions from any enclosing
     * when() closure. Outside of a when() closure, the statements are
     * emitted unconditionally.
     *
     * @param  array<int, string>|string  $actions
     */
    public function anyoneCan(array|string $actions): self
    {
        foreach ($this->normalizeActions($actions) as $action) {
            $this->store->attach($this->resourceType, null, new PolicyStatement(
                effect: Effect::Allow,
                action: $action,
                conditions: $this->activeConditions,
                source: "resource-builder:{$this->resourceType}",
            ));
        }

        return $this;
    }

    /**
     * Remove type-level statements for the given action on this resource.
     */
    public function detach(string $action): self
    {
        $this->store->detach($this->resourceType, null, $action);

        return $this;
    }

    /**
     * Remove a single statement by its primary key.
     *
     * Use this when multiple statements with the same action exist on this resource
     * (different effects or conditions) and only one of them should be removed.
     */
    public function detachById(string $id): self
    {
        $this->store->detachById($id);

        return $this;
    }

    /**
     * @param  array<int, string>|string  $actions
     * @return array<int, string>
     */
    private function normalizeActions(array|string $actions): array
    {
        return is_array($actions) ? $actions : [$actions];
    }
}
