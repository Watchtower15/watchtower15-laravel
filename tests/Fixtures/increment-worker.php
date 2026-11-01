<?php

declare(strict_types=1);

/*
 * A child process of LB28: increments one bucket key N times through the bridge's bucket store on Redis, outside any
 * Laravel application. Arguments: <redis host> <buffer prefix> <bucket> <key> <times>.
 */

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Metrics\StoreScope;

require __DIR__ . '/../../../../vendor/autoload.php';

[, $host, $prefix, $bucket, $key, $times] = $argv;

$app = new Container();
Container::setInstance($app);
$app->instance('config', new Repository([
    'cache' => ['default' => 'redis', 'prefix' => 'wt15test', 'stores' => ['redis' => ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default']]],
    'database' => ['redis' => [
        'client' => 'phpredis',
        'options' => ['prefix' => ''],
        'default' => ['host' => $host, 'port' => 6379, 'database' => 0],
        'cache' => ['host' => $host, 'port' => 6379, 'database' => 1],
    ]],
]));
$app->singleton('redis', static fn ($app): RedisManager => new RedisManager($app, 'phpredis', $app['config']['database.redis']));

$store = new CacheBucketStore(new CacheManager($app), null, $prefix, StoreScope::Fleet, 'redis', [$key => 'count']);
for ($i = 0; $i < (int) $times; $i++) {
    $store->increment((int) $bucket, $key, 1, 3600);
}
