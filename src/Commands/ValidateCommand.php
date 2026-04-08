<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Commands;

use DynamikDev\PolicyEngine\Contracts\DocumentParser;
use DynamikDev\PolicyEngine\Support\PathValidator;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ValidateCommand extends Command
{
    protected $signature = 'policy-engine:validate {path}';

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

        try {
            $validatedPath = PathValidator::validate($pathArg);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $content = file_get_contents($validatedPath);

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
