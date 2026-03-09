<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Commands;

use Illuminate\Cache\CacheManager;
use Illuminate\Console\Command;

class CacheClearCommand extends Command
{
    protected $signature = 'primitives:cache-clear';

    protected $description = 'Clear the policy engine permission cache';

    public function handle(CacheManager $cache): int
    {
        /** @var string $storeName */
        $storeName = config('policy-engine.cache.store', 'default');

        $cache->store($storeName === 'default' ? null : $storeName)->clear();

        $this->info('Policy engine cache cleared.');

        return self::SUCCESS;
    }
}
