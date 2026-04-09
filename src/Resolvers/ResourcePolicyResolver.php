<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Resolvers;

use DynamikDev\Marque\Contracts\PolicyResolver;
use DynamikDev\Marque\Contracts\ResourcePolicyStore;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\PolicyStatement;
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
