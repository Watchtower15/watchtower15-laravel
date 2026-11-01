<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Metrics;

use Illuminate\Contracts\Config\Repository;
use WatchTower15\Metrics\StoreScope;

/**
 * D5's driver rule: only a store whose driver increments atomically and gives locks holds the buckets. It reads configuration
 * only (never the store), with Laravel's fallbacks for the `database` driver's connections.
 *
 * @internal
 */
final class StoreRule
{
    public static function decide(Repository $config, ?string $bufferStore, bool $testing): StoreDecision
    {
        $default = $config->get('cache.default');
        $store = $bufferStore ?? (is_string($default) ? $default : 'array');
        $definition = $config->get('cache.stores.' . $store);
        if (! is_array($definition)) {
            return new StoreDecision($store, null, 'the cache store [' . $store . '] is not configured', null);
        }
        $driver = is_string($definition['driver'] ?? null) ? $definition['driver'] : null;

        switch ($driver) {
            case 'redis':
            case 'memcached':
            case 'dynamodb':
                return new StoreDecision($store, $driver, null, StoreScope::Fleet);
            case 'database':
                $connection = is_string($definition['connection'] ?? null) ? $definition['connection'] : self::defaultConnection($config);
                $lockConnection = is_string($definition['lock_connection'] ?? null) ? $definition['lock_connection'] : $connection;
                foreach (array_unique([$connection, $lockConnection]) as $name) {
                    if ($name !== null && $config->get('database.connections.' . $name . '.driver') === 'sqlite') {
                        return new StoreDecision($store, $driver, 'the database cache store [' . $store . '] runs on SQLite, which loses increments under concurrent requests: use Redis, or a database store on MySQL or PostgreSQL', null);
                    }
                }

                return new StoreDecision($store, $driver, null, StoreScope::Fleet);
            case 'array':
                if ($testing) {
                    return new StoreDecision($store, $driver, null, StoreScope::Process);
                }

                return new StoreDecision($store, $driver, 'the array cache store [' . $store . '] lives for one process only and is allowed under APP_ENV=testing alone', null);
            case 'file':
                return new StoreDecision($store, $driver, 'the file cache store [' . $store . '] loses counts: its increment is not atomic across processes', null);
            case 'apc':
                return new StoreDecision($store, $driver, 'the apc cache store [' . $store . '] has no lock and is not shared between PHP-FPM and the command line', null);
            default:
                return new StoreDecision($store, $driver, 'the cache store [' . $store . '] (driver ' . ($driver ?? 'unset') . ') is not supported: use Redis, Memcached, DynamoDB, or a database store on MySQL or PostgreSQL', null);
        }
    }

    private static function defaultConnection(Repository $config): ?string
    {
        $default = $config->get('database.default');

        return is_string($default) ? $default : null;
    }
}
