<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Concerns;

use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\DTOs\Resource;

/**
 * Exposes a model as a policy Resource for evaluation by Marque's resolvers
 * and conditions.
 *
 * By default, the Resource carries no attributes. This is intentional: the
 * Resource DTO is consumed by potentially third-party resolvers and
 * conditions, and exposing the full set of fillable columns risks leaking
 * data the developer did not intend to share with authorization logic.
 *
 * To make attributes available to conditions (for example, an
 * AttributeEqualsCondition that compares principal.id to resource.user_id),
 * override resourceAttributes() and return only the fields you want to
 * expose:
 *
 *     protected function resourceAttributes(): array
 *     {
 *         return [
 *             'user_id' => $this->user_id,
 *             'status' => $this->status,
 *         ];
 *     }
 *
 * Upgrade note (BC break): prior versions returned
 * $this->only($this->getFillable()) by default. Consumers relying on the
 * implicit fillable export must override resourceAttributes() with an
 * explicit allowlist of fields, otherwise conditions referencing those
 * keys will resolve to null.
 */
trait HasResourcePolicies
{
    public function toPolicyResource(): Resource
    {
        return new Resource(
            type: $this->getMorphClass(),
            id: $this->getKey(),
            attributes: $this->resourceAttributes(),
        );
    }

    /** @return array<string, mixed> */
    protected function resourceAttributes(): array
    {
        return [];
    }

    public function attachPolicy(PolicyStatement $statement): void
    {
        app(ResourcePolicyStore::class)->attach(
            $this->getMorphClass(),
            $this->getKey(),
            $statement,
        );
    }

    public function detachPolicy(string $action): void
    {
        app(ResourcePolicyStore::class)->detach(
            $this->getMorphClass(),
            $this->getKey(),
            $action,
        );
    }
}
