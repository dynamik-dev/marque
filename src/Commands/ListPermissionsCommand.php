<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Commands;

use DynamikDev\Marque\Contracts\PermissionStore;
use Illuminate\Console\Command;

class ListPermissionsCommand extends Command
{
    protected $signature = 'marque:permissions';

    protected $description = 'List all registered permissions';

    public function handle(PermissionStore $store): int
    {
        $permissions = $store->all();

        if ($permissions->isEmpty()) {
            $this->info('No permissions registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Description'],
            $permissions->map(static fn ($permission) => [
                $permission->id,
                $permission->description ?? '',
            ]),
        );

        return self::SUCCESS;
    }
}
