<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Commands;

use DynamikDev\Marque\Contracts\Evaluator;
use DynamikDev\Marque\DTOs\Context;
use DynamikDev\Marque\DTOs\EvaluationRequest;
use DynamikDev\Marque\DTOs\EvaluationResult;
use DynamikDev\Marque\DTOs\Principal;
use DynamikDev\Marque\Enums\Decision;
use DynamikDev\Marque\Support\SubjectParser;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ExplainCommand extends Command
{
    protected $signature = 'marque:explain {subject} {permission} {--scope=}';

    protected $description = 'Explain the evaluation trace for a permission check';

    public function handle(Evaluator $evaluator): int
    {
        $subjectArg = $this->argument('subject');
        $permissionArg = $this->argument('permission');

        if (! is_string($subjectArg) || ! is_string($permissionArg)) {
            $this->error('Subject and permission arguments must be strings.');

            return self::FAILURE;
        }

        try {
            [$subjectType, $subjectId] = SubjectParser::parse($subjectArg);
        } catch (InvalidArgumentException) {
            $this->error("Invalid subject format. Expected 'type::id' (e.g., user::42).");

            return self::FAILURE;
        }

        if (! config('marque.trace')) {
            $this->error('Explain mode is disabled. Set marque.trace to true in your configuration.');

            return self::FAILURE;
        }

        $scopeOption = $this->option('scope');
        $scope = is_string($scopeOption) ? $scopeOption : null;

        $request = new EvaluationRequest(
            principal: new Principal(type: $subjectType, id: $subjectId),
            action: $permissionArg,
            resource: null,
            context: new Context(scope: $scope),
        );

        $result = $evaluator->evaluate($request);

        $this->renderResult($subjectType, $subjectId, $permissionArg, $scope, $result);

        return self::SUCCESS;
    }

    private function renderResult(
        string $subjectType,
        string $subjectId,
        string $permission,
        ?string $scope,
        EvaluationResult $result,
    ): void {
        $this->newLine();
        $this->line("  <info>Subject:</info>    {$subjectType}:{$subjectId}");
        $this->line("  <info>Permission:</info> {$permission}");

        $resultLabel = strtoupper($result->decision->value);
        $resultStyle = $result->decision === Decision::Allow ? 'info' : 'error';
        $this->line("  <info>Result:</info>     <{$resultStyle}>{$resultLabel}</{$resultStyle}>");
        $this->line("  <info>Decided by:</info> {$result->decidedBy}");

        if ($scope !== null) {
            $this->line("  <info>Scope:</info>      {$scope}");
        }

        $this->newLine();

        if ($result->matchedStatements !== []) {
            $this->line('  <comment>Matched statements:</comment>');

            foreach ($result->matchedStatements as $statement) {
                $effect = strtoupper($statement->effect->name);
                $this->line("    [{$effect}] {$statement->action} (source: {$statement->source})");
            }
        } else {
            $this->line('  <comment>Matched statements:</comment> (none)');
        }

        $this->newLine();
        $this->line('  <info>Cache hit:</info>  N/A');
        $this->newLine();
    }
}
