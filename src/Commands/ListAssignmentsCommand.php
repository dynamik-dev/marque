<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Commands;

use DynamikDev\Marque\Contracts\AssignmentStore;
use DynamikDev\Marque\Models\Assignment;
use DynamikDev\Marque\Support\SubjectParser;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ListAssignmentsCommand extends Command
{
    protected $signature = 'marque:assignments {subject?} {--scope=}';

    protected $description = 'List role assignments for a subject or scope';

    public function handle(AssignmentStore $store): int
    {
        $subject = $this->argument('subject');
        $scope = $this->option('scope');

        if (is_string($subject)) {
            return $this->listForSubject($store, $subject, is_string($scope) ? $scope : null);
        }

        if (is_string($scope)) {
            return $this->listForScope($store, $scope);
        }

        $this->showUsage();

        return self::SUCCESS;
    }

    private function listForSubject(AssignmentStore $store, string $subject, ?string $scope): int
    {
        try {
            [$type, $id] = SubjectParser::parse($subject);
        } catch (InvalidArgumentException) {
            $this->error("Invalid subject format. Expected 'type::id' (e.g., user::42).");

            return self::FAILURE;
        }

        $assignments = $scope !== null
            ? $store->forSubjectInScope($type, $id, $scope)
            : $store->forSubject($type, $id);

        return $this->renderAssignments($assignments);
    }

    private function listForScope(AssignmentStore $store, string $scope): int
    {
        return $this->renderAssignments($store->subjectsInScope($scope));
    }

    /**
     * @param  Collection<int, Assignment>  $assignments
     */
    private function renderAssignments(Collection $assignments): int
    {
        if ($assignments->isEmpty()) {
            $this->info('No assignments found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Subject Type', 'Subject ID', 'Role', 'Scope'],
            $assignments->map(static fn ($assignment) => [
                $assignment->subject_type,
                $assignment->subject_id,
                $assignment->role_id,
                $assignment->scope ?? '(global)',
            ]),
        );

        return self::SUCCESS;
    }

    private function showUsage(): void
    {
        $this->line('Usage:');
        $this->line('  marque:assignments user::42          List assignments for a subject');
        $this->line('  marque:assignments user::42 --scope=group::5  List scoped assignments');
        $this->line('  marque:assignments --scope=group::5  List all assignments in a scope');
    }
}
