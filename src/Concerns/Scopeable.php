<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Concerns;

use DynamikDev\PolicyEngine\Attributes\ScopeType;
use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use DynamikDev\PolicyEngine\Models\Assignment;
use Illuminate\Support\Collection;
use ReflectionClass;

/**
 * Turns an Eloquent model into a scope that can contain members.
 *
 * The scope type is resolved in order:
 * 1. `#[ScopeType('team')]` attribute on the class
 * 2. `protected string $scopeType = 'team'` property
 * 3. Lowercased class basename (Team → 'team')
 */
trait Scopeable
{
    public function toScope(): string
    {
        return $this->getScopeType().'::'.$this->getKey();
    }

    /**
     * Get the scope type string for this model.
     *
     * Checks for a #[ScopeType] attribute first, then a $scopeType property,
     * then falls back to the lowercased class basename.
     */
    public function getScopeType(): string
    {
        $attribute = (new ReflectionClass(static::class))
            ->getAttributes(ScopeType::class)[0] ?? null;

        if ($attribute !== null) {
            return $attribute->newInstance()->type;
        }

        if (property_exists($this, 'scopeType') && is_string($this->scopeType)) {
            return $this->scopeType;
        }

        return strtolower(class_basename(static::class));
    }

    /** @return Collection<int, Assignment> */
    public function members(): Collection
    {
        return app(AssignmentStore::class)->subjectsInScope($this->toScope());
    }

    /** @return Collection<int, Assignment> */
    public function membersWithRole(string $roleId): Collection
    {
        return app(AssignmentStore::class)->subjectsInScope($this->toScope(), $roleId);
    }
}
