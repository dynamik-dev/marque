<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Commands;

use DynamikDev\PolicyEngine\Contracts\AssignmentStore;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ListAssignmentsCommand extends Command
{
    protected $signature = 'primitives:assignments {subject?} {--scope=}';

    protected $description = 'List role assignments for a subject or scope';

    public function handle(AssignmentStore $store): int
    {
        $subject = $this->argument('subject');
        $scope = $this->option('scope');

        if ($subject !== null) {
            return $this->listForSubject($store, (string) $subject, $scope);
        }

        if ($scope !== null) {
            return $this->listForScope($store, (string) $scope);
        }

        $this->showUsage();

        return self::SUCCESS;
    }

    private function listForSubject(AssignmentStore $store, string $subject, mixed $scope): int
    {
        [$type, $id] = $this->parseSubject($subject);

        if ($type === null) {
            $this->error("Invalid subject format. Expected 'type::id' (e.g., user::42).");

            return self::FAILURE;
        }

        $assignments = $scope !== null
            ? $store->forSubjectInScope($type, $id, (string) $scope)
            : $store->forSubject($type, $id);

        return $this->renderAssignments($assignments);
    }

    private function listForScope(AssignmentStore $store, string $scope): int
    {
        return $this->renderAssignments($store->subjectsInScope($scope));
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
        $this->line('  primitives:assignments user::42          List assignments for a subject');
        $this->line('  primitives:assignments user::42 --scope=group::5  List scoped assignments');
        $this->line('  primitives:assignments --scope=group::5  List all assignments in a scope');
    }
}
