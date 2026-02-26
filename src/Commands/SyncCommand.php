<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Commands;

use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'primitives:sync';

    protected $description = 'Re-run the permission seeder to sync permissions';

    public function handle(): int
    {
        $seederClass = 'PermissionSeeder';

        try {
            $this->call('db:seed', ['--class' => $seederClass]);

            $this->info('Permission sync completed successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to sync permissions: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
