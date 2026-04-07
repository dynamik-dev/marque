<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Commands;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Console\Command;

class CacheClearCommand extends Command
{
    protected $signature = 'primitives:cache-clear {--force : Skip confirmation when flushing an untaggable store}';

    protected $description = 'Clear the policy engine permission cache';

    public function handle(CacheManager $cache): int
    {
        /** @var string $storeName */
        $storeName = config('policy-engine.cache.store', 'default');

        $store = $cache->store($storeName === 'default' ? null : $storeName);

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
