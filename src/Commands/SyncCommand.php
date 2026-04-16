<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Commands;

use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'marque:sync';

    protected $description = 'Re-run the permission seeder to sync permissions';

    public function handle(): int
    {
        $seederClass = config('marque.seeder_class');

        if (! is_string($seederClass) || trim($seederClass) === '') {
            $this->error('Failed to sync permissions: configure marque.seeder_class first (e.g. \\Database\\Seeders\\PermissionSeeder).');

            return self::FAILURE;
        }

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
