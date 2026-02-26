<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Concerns;

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use Illuminate\Support\Collection;

/**
 * Turns an Eloquent model into a scope that can contain members.
 *
 * The using model must define a protected string `$scopeType` property
 * (e.g., 'group', 'team', 'org') and be an Eloquent Model
 * (provides getKey()).
 */
trait Scopeable
{
    /**
     * Return the canonical scope string for this model instance.
     *
     * Format: `{type}::{id}` (e.g., "group::5").
     */
    public function toScope(): string
    {
        return $this->scopeType.'::'.$this->getKey();
    }

    /**
     * Get all assignments within this scope.
     *
     * @return Collection<int, \DynamikDev\PolicyEngine\Models\Assignment>
     */
    public function members(): Collection
    {
        return app(AssignmentStore::class)->subjectsInScope($this->toScope());
    }

    /**
     * Get all assignments within this scope filtered by a specific role.
     *
     * @return Collection<int, \DynamikDev\PolicyEngine\Models\Assignment>
     */
    public function membersWithRole(string $roleId): Collection
    {
        return app(AssignmentStore::class)->subjectsInScope($this->toScope(), $roleId);
    }
}
