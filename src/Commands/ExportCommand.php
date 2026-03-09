<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Commands;

use DynamikDev\PolicyEngine\PrimitivesManager;
use Illuminate\Console\Command;

class ExportCommand extends Command
{
    protected $signature = 'primitives:export {--scope=} {--path=} {--stdout}';

    protected $description = 'Export the current authorization state to JSON';

    public function handle(PrimitivesManager $manager): int
    {
        $scopeOption = $this->option('scope');
        $scope = is_string($scopeOption) ? $scopeOption : null;
        $pathOption = $this->option('path');
        $path = is_string($pathOption) ? $pathOption : null;

        try {
            if ($path) {
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
