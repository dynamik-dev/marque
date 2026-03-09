<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Commands;

use DynamikDev\PolicyEngine\DTOs\ImportOptions;
use DynamikDev\PolicyEngine\DTOs\ImportResult;
use DynamikDev\PolicyEngine\PrimitivesManager;
use Illuminate\Console\Command;

class ImportCommand extends Command
{
    protected $signature = 'primitives:import {path} {--dry-run} {--skip-assignments} {--replace} {--force}';

    protected $description = 'Import a policy document from a JSON file';

    public function handle(PrimitivesManager $manager): int
    {
        $pathArg = $this->argument('path');

        if (! is_string($pathArg)) {
            $this->error('Path argument must be a string.');

            return self::FAILURE;
        }

        $path = $pathArg;

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $replace = (bool) $this->option('replace');

        if ($replace && ! $this->option('force') && ! $this->confirm('This will delete all existing permissions, roles, assignments, and boundaries. Continue?')) {
            $this->info('Import cancelled.');

            return self::SUCCESS;
        }

        $options = new ImportOptions(
            validate: true,
            merge: ! $replace,
            dryRun: (bool) $this->option('dry-run'),
            skipAssignments: (bool) $this->option('skip-assignments'),
        );

        try {
            $result = $manager->import($path, $options);
        } catch (\InvalidArgumentException $e) {
            $this->error("Failed to parse document: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->renderResult($result, $options->dryRun);

        return self::SUCCESS;
    }

    private function renderResult(ImportResult $result, bool $dryRun): void
    {
        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run — no changes applied.');
        }

        $this->newLine();
        $this->line('  <comment>Import summary:</comment>');

        $this->line('  Permissions created: <info>'.count($result->permissionsCreated).'</info>');

        if ($result->permissionsCreated !== []) {
            foreach ($result->permissionsCreated as $permission) {
                $this->line("    - {$permission}");
            }
        }

        $this->line('  Roles created: <info>'.count($result->rolesCreated).'</info>');

        if ($result->rolesCreated !== []) {
            foreach ($result->rolesCreated as $role) {
                $this->line("    - {$role}");
            }
        }

        $this->line('  Roles updated: <info>'.count($result->rolesUpdated).'</info>');

        if ($result->rolesUpdated !== []) {
            foreach ($result->rolesUpdated as $role) {
                $this->line("    - {$role}");
            }
        }

        $this->line("  Assignments created: <info>{$result->assignmentsCreated}</info>");

        if ($result->warnings !== []) {
            $this->newLine();
            $this->line('  <comment>Warnings:</comment>');

            foreach ($result->warnings as $warning) {
                $this->line("    <fg=yellow>⚠</> {$warning}");
            }
        }

        $this->newLine();
    }
}
