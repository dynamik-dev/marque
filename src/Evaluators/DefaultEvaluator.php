<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Evaluators;

use DynamikDev\PolicyEngine\Contracts\ConditionRegistry;
use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\Contracts\Matcher;
use DynamikDev\PolicyEngine\Contracts\PolicyResolver;
use DynamikDev\PolicyEngine\DTOs\EvaluationRequest;
use DynamikDev\PolicyEngine\DTOs\EvaluationResult;
use DynamikDev\PolicyEngine\DTOs\PolicyStatement;
use DynamikDev\PolicyEngine\DTOs\Principal;
use DynamikDev\PolicyEngine\DTOs\Resource;
use DynamikDev\PolicyEngine\Enums\Decision;
use DynamikDev\PolicyEngine\Enums\Effect;
use Illuminate\Support\Collection;

class DefaultEvaluator implements Evaluator
{
    /**
     * @param  PolicyResolver[]  $resolvers
     */
    public function __construct(
        private readonly array $resolvers,
        private readonly Matcher $matcher,
        private readonly ?ConditionRegistry $conditionRegistry = null,
    ) {}

    public function evaluate(EvaluationRequest $request): EvaluationResult
    {
        $applicable = $this->collectApplicableStatements($request);

        $traceEnabled = (bool) config('policy-engine.trace');
        $matchedStatements = $traceEnabled ? $applicable->all() : [];

        $deny = $applicable->first(fn (PolicyStatement $s): bool => $s->effect === Effect::Deny);

        if ($deny !== null) {
            return new EvaluationResult(
                decision: Decision::Deny,
                decidedBy: $deny->source,
                matchedStatements: $matchedStatements,
            );
        }

        $allow = $applicable->first(fn (PolicyStatement $s): bool => $s->effect === Effect::Allow);

        if ($allow !== null) {
            return new EvaluationResult(
                decision: Decision::Allow,
                decidedBy: $allow->source,
                matchedStatements: $matchedStatements,
            );
        }

        return new EvaluationResult(
            decision: Decision::Deny,
            decidedBy: 'default-deny',
            matchedStatements: [],
        );
    }

    /**
     * Collect all statements from all resolvers and filter to those applicable to the request.
     *
     * @return Collection<int, PolicyStatement>
     */
    private function collectApplicableStatements(EvaluationRequest $request): Collection
    {
        $all = collect();

        foreach ($this->resolvers as $resolver) {
            $all = $all->merge($resolver->resolve($request));
        }

        return $all->filter(fn (PolicyStatement $statement): bool => $this->matchesAction($statement, $request)
            && $this->matchesPrincipal($statement, $request->principal)
            && $this->matchesResource($statement, $request->resource)
            && $this->conditionsPass($statement, $request)
        )->values();
    }

    private function matchesAction(PolicyStatement $statement, EvaluationRequest $request): bool
    {
        return $this->matcher->matches($statement->action, $request->action);
    }

    private function matchesPrincipal(PolicyStatement $statement, Principal $principal): bool
    {
        if ($statement->principalPattern === null) {
            return true;
        }

        if ($statement->principalPattern === '*') {
            return true;
        }

        return $statement->principalPattern === "{$principal->type}:{$principal->id}";
    }

    private function matchesResource(PolicyStatement $statement, ?Resource $resource): bool
    {
        if ($statement->resourcePattern === null) {
            return true;
        }

        if ($statement->resourcePattern === '*') {
            return true;
        }

        if ($resource === null) {
            return false;
        }

        return $statement->resourcePattern === "{$resource->type}:{$resource->id}";
    }

    private function conditionsPass(PolicyStatement $statement, EvaluationRequest $request): bool
    {
        if ($statement->conditions === [] || $this->conditionRegistry === null) {
            return true;
        }

        foreach ($statement->conditions as $condition) {
            $evaluator = $this->conditionRegistry->evaluatorFor($condition->type);
            if (! $evaluator->passes($condition, $request)) {
                return false;
            }
        }

        return true;
    }
}
