<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Commands;

use DynamikDev\Marque\Contracts\RoleStore;
use Illuminate\Console\Command;

class ListRolesCommand extends Command
{
    protected $signature = 'marque:roles';

    protected $description = 'List all roles and their permissions';

    public function handle(RoleStore $store): int
    {
        $roles = $store->all();

        if ($roles->isEmpty()) {
            $this->info('No roles registered.');

            return self::SUCCESS;
        }

        /** @var array<int, string> $roleIds */
        $roleIds = $roles->pluck('id')->all();
        $allPermissions = $store->permissionsForRoles($roleIds);

        foreach ($roles as $role) {
            $permissions = $allPermissions[$role->id] ?? [];

            $this->table(
                ['ID', 'Name', 'System', 'Permissions'],
                [[
                    $role->id,
                    $role->name,
                    $role->is_system ? 'Yes' : 'No',
                    count($permissions),
                ]],
            );

            if ($permissions !== []) {
                $this->line('  Permissions:');
                foreach ($permissions as $permission) {
                    $this->line("    - {$permission}");
                }
                $this->newLine();
            }
        }

        return self::SUCCESS;
    }
}
