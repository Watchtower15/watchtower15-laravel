<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use WatchTower15\Client;
use WatchTower15\Laravel\Config\Settings;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Laravel\Metrics\Pipe;
use WatchTower15\Laravel\Support\CarbonClock;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Tests\Support\SpyStore;

/** Builds the scoped aggregator over a spy that wraps the bridge's bucket store. */
trait SpiesOnTheStore
{
    /** A spy over the bucket store, built into the aggregator. */
    private function spyStore(): SpyStore
    {
        $spy = null;
        $this->app->scoped(Aggregator::class, function (Application $app) use (&$spy): Aggregator {
            $spy = new SpyStore($app->make(CacheBucketStore::class));
            $settings = $app->make(Settings::class);
            $pipe = $app->make(Pipe::class);
            $client = $app->make('wt15.metrics.client');
            self::assertInstanceOf(Client::class, $client);

            return new Aggregator(client: $client, store: $spy, allowList: $pipe->validated()->allowList(), heartbeat: $pipe->validated()->heartbeat, maxBuckets: $settings->maxBuckets(), clock: new CarbonClock());
        });
        $this->app->make(Aggregator::class);
        self::assertInstanceOf(SpyStore::class, $spy);

        return $spy;
    }
}
