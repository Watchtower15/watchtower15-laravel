<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Laravel\Tests\Support\Wire;
use WatchTower15\Metrics\StoreScope;

/** LB28 on Redis: scope, concurrency and silence towards Pulse and Telescope. */
final class RedisStoreTest extends PipeTestCase
{
    public function testTheRedisStoreIsAFleetStoreAndTwoHundredIncrementsFromChildrenAreExactlyTwoHundred(): void
    {
        self::assertSame(StoreScope::Fleet, $this->bucketStore()->scope());

        $script = __DIR__ . '/../Fixtures/increment-worker.php';
        $children = [];
        for ($i = 0; $i < 4; $i++) {
            $process = proc_open(
                [PHP_BINARY, $script, (string) self::host(), $this->prefix, '123456', 'orders.shipped', '50'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $children[] = [$process, $pipes];
        }
        foreach ($children as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $exit = proc_close($process);
            self::assertSame(0, $exit, $output);
        }

        self::assertSame(200, $this->counted('orders.shipped', 123456));
    }

    public function testNoCacheEventIsDispatchedByAnIncrementAGaugeOrAFlush(): void
    {
        $seen = [];
        $this->app['events']->listen('Illuminate\Cache\Events\*', static function (string $name) use (&$seen): void {
            $seen[] = $name;
        });
        Wire::standard();

        Wt15::increment('orders.shipped');
        Wt15::gauge('queue.depth', 3.5);
        $this->closeBucket();
        self::assertSame(0, Artisan::call('wt15:flush'));

        self::assertCount(1, $this->posts(), 'the bucket was read and sent');
        self::assertSame([], $seen, 'the bridge\'s keys never reach Pulse or Telescope');
        self::assertInstanceOf(CacheBucketStore::class, $this->bucketStore());
        Http::assertSentCount(1);
    }
}
