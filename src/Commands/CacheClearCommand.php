<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Commands;

use DynamikDev\PolicyEngine\Support\CacheStoreResolver;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Console\Command;

class CacheClearCommand extends Command
{
    protected $signature = 'policy-engine:cache-clear {--force : Skip confirmation when flushing an untaggable store}';

    protected $description = 'Clear the policy engine permission cache';

    public function handle(CacheManager $cache): int
    {
        $store = CacheStoreResolver::store($cache);

        if ($store instanceof Repository && $store->supportsTags()) {
            $store->tags(['policy-engine'])->flush();
            $this->info('Policy engine cache cleared (tagged).');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Cache driver does not support tags. This will clear the entire cache store. Continue?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $store->clear();
        $this->info('Policy engine cache cleared (full store flush — consider using a tagged driver or dedicated store).');

        return self::SUCCESS;
    }
}
