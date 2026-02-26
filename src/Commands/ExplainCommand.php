<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Commands;

use DynamikDev\PolicyEngine\Contracts\Evaluator;
use DynamikDev\PolicyEngine\DTOs\EvaluationTrace;
use Illuminate\Console\Command;
use RuntimeException;

class ExplainCommand extends Command
{
    protected $signature = 'primitives:explain {subject} {permission} {--scope=}';

    protected $description = 'Explain the evaluation trace for a permission check';

    public function handle(Evaluator $evaluator): int
    {
        [$subjectType, $subjectId] = $this->parseSubject((string) $this->argument('subject'));

        if ($subjectType === null) {
            $this->error("Invalid subject format. Expected 'type::id' (e.g., user::42).");

            return self::FAILURE;
        }

        $permission = $this->buildPermissionString(
            (string) $this->argument('permission'),
            $this->option('scope'),
        );

        try {
            $trace = $evaluator->explain($subjectType, $subjectId, $permission);
        } catch (RuntimeException $e) {
            $this->error('Explain mode is disabled. Set policy-engine.explain to true in your configuration.');

            return self::FAILURE;
        }

        $this->renderTrace($trace);

        return self::SUCCESS;
    }

    /**
     * @return array{?string, ?string}
     */
    private function parseSubject(string $subject): array
    {
        if (! str_contains($subject, '::')) {
            return [null, null];
        }

        $parts = explode('::', $subject, 2);

        if ($parts[0] === '' || $parts[1] === '') {
            return [null, null];
        }

        return [$parts[0], $parts[1]];
    }

    private function buildPermissionString(string $permission, mixed $scope): string
    {
        if ($scope === null) {
            return $permission;
        }

        return $permission.':'.(string) $scope;
    }

    private function renderTrace(EvaluationTrace $trace): void
    {
        $this->newLine();
        $this->line("  <info>Subject:</info>    {$trace->subject}");
        $this->line("  <info>Permission:</info> {$trace->required}");

        $resultLabel = strtoupper($trace->result);
        $resultStyle = $trace->result === 'allow' ? 'info' : 'error';
        $this->line("  <info>Result:</info>     <{$resultStyle}>{$resultLabel}</{$resultStyle}>");

        $this->newLine();
        $this->line('  <comment>Assignments checked:</comment>');

        if ($trace->assignments === []) {
            $this->line('    (none)');
        }

        foreach ($trace->assignments as $assignment) {
            $scopeLabel = $assignment['scope'] ?? 'global';
            $this->line("    Role: <info>{$assignment['role']}</info> (scope: {$scopeLabel})");

            if ($assignment['permissions_checked'] !== []) {
                $this->line('      Permissions: '.implode(', ', $assignment['permissions_checked']));
            }
        }

        $this->newLine();
        $this->line('  <info>Boundary:</info>   '.($trace->boundary ?? 'none'));
        $this->line('  <info>Cache hit:</info>  '.($trace->cacheHit ? 'Yes' : 'No'));
        $this->newLine();
    }
}
