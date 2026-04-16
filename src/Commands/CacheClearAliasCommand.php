<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Commands;

use Illuminate\Console\Command;

class CacheClearAliasCommand extends Command
{
    protected $signature = 'marque:cache-clear';

    protected $description = '[Deprecated] Alias for marque:cache:clear. Use marque:cache:clear instead.';

    public function handle(): int
    {
        $this->warn('marque:cache-clear is deprecated and will be removed in a future release. Use marque:cache:clear instead.');

        return $this->call('marque:cache:clear');
    }
}
