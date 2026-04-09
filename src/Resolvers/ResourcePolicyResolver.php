<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Resolvers;

use DynamikDev\PolicyEngine\Contracts\PolicyResolver;
use DynamikDev\PolicyEngine\Contracts\ResourcePolicyStore;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\PolicyStatement;
use Illuminate\Support\Collection;

class ResourcePolicyResolver implements PolicyResolver
{
    public function __construct(
        private readonly ResourcePolicyStore $store,
    ) {}

    /**
     * @return Collection<int, PolicyStatement>
     */
    public function resolve(EvaluationRequest $request): Collection
    {
        if ($request->resource === null) {
            return collect();
        }

        return $this->store->forResource($request->resource->type, $request->resource->id);
    }
}
