<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Commands;

use DynamikDev\PolicyEngine\Contracts\DocumentParser;
use Illuminate\Console\Command;

class ValidateCommand extends Command
{
    protected $signature = 'primitives:validate {path}';

    protected $description = 'Validate a policy document without importing it';

    public function handle(DocumentParser $parser): int
    {
        $path = (string) $this->argument('path');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $content = file_get_contents($path);

        $result = $parser->validate($content);

        if ($result->valid) {
            $this->info('Policy document is valid.');

            return self::SUCCESS;
        }

        $this->error('Policy document is invalid:');

        foreach ($result->errors as $error) {
            $this->line("  - {$error}");
        }

        return self::FAILURE;
    }
}
