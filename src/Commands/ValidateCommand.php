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
        $pathArg = $this->argument('path');

        if (! is_string($pathArg)) {
            $this->error('Path argument must be a string.');

            return self::FAILURE;
        }

        if (! file_exists($pathArg)) {
            $this->error("File not found: {$pathArg}");

            return self::FAILURE;
        }

        $content = file_get_contents($pathArg);

        if ($content === false) {
            $this->error("Could not read file: {$pathArg}");

            return self::FAILURE;
        }

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
