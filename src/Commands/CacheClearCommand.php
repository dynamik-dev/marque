<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Commands;

use DynamikDev\PolicyEngine\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Console\Command;

class CacheClearCommand extends Command
{
    protected $signature = 'policy-engine:cache-clear';

    protected $description = 'Clear the policy engine permission cache';

    public function handle(CacheManager $cache): int
    {
        CacheStoreResolver::flush($cache);

        $store = CacheStoreResolver::store($cache);
        $mechanism = ($store instanceof Repository && $store->supportsTags())
            ? 'tagged flush'
            : 'generation counter incremented — stale entries expire via TTL';

        $this->info("Policy engine cache cleared ({$mechanism}).");

        return self::SUCCESS;
    }
}
