<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Evaluators;

use DynamikDev\Marque\Contracts\ConditionRegistry;
use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\Contracts\Matcher;
use DynamikDev\Marque\Contracts\PolicyResolver;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\EvaluationResult;
use DynamikDev\Marque\DTOs\PolicyStatement;
use DynamikDev\Marque\DTOs\Principal;
use DynamikDev\Marque\DTOs\Resource;
use DynamikDev\Marque\Enums\Decision;
use DynamikDev\Marque\Enums\Effect;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        $traceEnabled = (bool) config('marque.trace');
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

        return $this->matcher->matches(
            $this->toMatchableIdentity($statement->principalPattern),
            $this->toMatchableIdentity("{$principal->type}:{$principal->id}"),
        );
    }

    private function matchesResource(PolicyStatement $statement, ?Resource $resource): bool
    {
        if ($statement->resourcePattern === null) {
            return true;
        }

        if ($resource === null) {
            // A bare `*` is a stand-alone wildcard that matches any resource,
            // including the resourceless case (e.g. action-only requests).
            // More specific patterns (`post:*`, `post:42`) require a resource.
            return $statement->resourcePattern === '*';
        }

        return $this->matcher->matches(
            $this->toMatchableIdentity($statement->resourcePattern),
            $this->toMatchableIdentity("{$resource->type}:{$resource->id}"),
        );
    }

    /**
     * Convert a `type:id` identity string into a dot-segmented form so it can
     * flow through the dot-aware Matcher (e.g. `user:5` -> `user.5`,
     * `user:*` -> `user.*`). Identity ids are treated as opaque strings;
     * wildcards (`*`) are preserved so the matcher can apply its zero-or-more
     * segment semantics.
     */
    private function toMatchableIdentity(string $identity): string
    {
        return str_replace(':', '.', $identity);
    }

    /**
     * Evaluate every condition attached to the statement.
     *
     * Fail-closed semantics: if the registry has no evaluator for a condition type
     * (admin typo, removed plugin) or the evaluator throws while running, the entire
     * statement is treated as not-applicable and the error is logged. This prevents a
     * single bad condition from bricking authorization across every subject assigned
     * to the role; the worst case becomes a missing allow (default-deny), not a 500.
     */
    private function conditionsPass(PolicyStatement $statement, EvaluationRequest $request): bool
    {
        if ($statement->conditions === [] || $this->conditionRegistry === null) {
            return true;
        }

        foreach ($statement->conditions as $condition) {
            try {
                $evaluator = $this->conditionRegistry->evaluatorFor($condition->type);

                if (! $evaluator->passes($condition, $request)) {
                    return false;
                }
            } catch (Throwable $e) {
                Log::warning('marque: condition evaluation failed; statement skipped (fail-closed)', [
                    'condition_type' => $condition->type,
                    'statement_source' => $statement->source,
                    'action' => $request->action,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                return false;
            }
        }

        return true;
    }
}
