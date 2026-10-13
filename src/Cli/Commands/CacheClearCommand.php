<?php

declare(strict_types=1);

namespace Nesto\Cli\Commands;

use Nesto\Cache\CacheConfig;
use Nesto\Cache\CacheModule;
use Nesto\Cache\Stores\DatabaseStore;
use Nesto\Cache\Stores\FileStore;
use Nesto\Cli\Command;
use Psr\SimpleCache\CacheInterface;

/**
 * `php nesto cache:clear` empties the application cache (Laravel's cache:clear): the CacheModule store of the
 * application when it has one, else the store configured by CACHE_DRIVER / config/cache.php.
 *
 *   php nesto cache:clear                 # the default store
 *   php nesto cache:clear redis           # another store of config/cache.php
 *   php nesto cache:clear --expired       # only remove expired entries (file and database stores)
 */
final class CacheClearCommand extends Command
{
    use InteractsWithPackages;

    protected string $signature = 'cache:clear {store? : A store of config/cache.php (default: CACHE_DRIVER)} {--expired : Only remove expired entries (file, database)}';
    protected string $description = 'Flush the application cache';

    public function handle(): int
    {
        $name = $this->argument('store');
        $cache = $name === null ? $this->applicationCache() : null;
        $label = 'application cache';
        if ($cache === null) {
            $options = CacheConfig::options(CacheConfig::load(), $name !== null ? (string) $name : null);
            $cache = CacheModule::createStore($options);
            $label = '"' . $options['name'] . '" cache store';
        }

        if ($this->option('expired')) {
            if ($cache instanceof FileStore || $cache instanceof DatabaseStore) {
                $removed = $cache->gc();
                $this->success("Removed $removed expired entr" . ($removed === 1 ? 'y' : 'ies') . " from the $label.");

                return self::SUCCESS;
            }
            $this->comment('This store expires entries by itself (memory, redis): nothing to do.');

            return self::SUCCESS;
        }
        if (! $cache->clear()) {
            $this->error("The $label could not be cleared.");

            return self::FAILURE;
        }
        $this->success(ucfirst($label) . ' cleared.');

        return self::SUCCESS;
    }

    /** The CacheModule store of the booted application, or null when the application has none. */
    private function applicationCache(): ?CacheInterface
    {
        $context = $this->context;
        if ($context === null || ! $context->hasRootModule()) {
            return null;
        }
        $app = $context->app();
        if (! $this->isProvided($app, CacheInterface::class)) {
            return null;
        }
        $cache = $app->get(CacheInterface::class);

        return $cache instanceof CacheInterface ? $cache : null;
    }
}
