<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Commands;

use DynamikDev\Marque\MarqueManager;
use Illuminate\Console\Command;

class ExportCommand extends Command
{
    protected $signature = 'marque:export {--scope=} {--path=} {--force}';

    protected $description = 'Export the current authorization state to JSON';

    public function handle(MarqueManager $manager): int
    {
        $scopeOption = $this->option('scope');
        $scope = is_string($scopeOption) ? $scopeOption : null;
        $pathOption = $this->option('path');
        $path = is_string($pathOption) ? $pathOption : null;

        try {
            if ($path) {
                if (file_exists($path) && ! $this->option('force')) {
                    if (! $this->confirm("File {$path} already exists. Overwrite?", false)) {
                        $this->info('Export cancelled.');

                        return self::SUCCESS;
                    }
                }

                $manager->exportToFile($path, $scope);
                $this->info("Exported to {$path}");

                return self::SUCCESS;
            }

            $this->line($manager->export($scope));
        } catch (\InvalidArgumentException $e) {
            $this->error("Export failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
