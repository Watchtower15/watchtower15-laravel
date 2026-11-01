<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Laravel\Tests\Fixtures\CountingToken;
use WatchTower15\Laravel\Tests\Fixtures\OrderRefunded;
use WatchTower15\Laravel\Tests\Support\Wire;
use WatchTower15\Laravel\Tests\TestCase;

/** LB16: `enabled` false, then true, then true with nothing configured on a refused store. */
final class EnabledTest extends TestCase
{
    protected function wt15Config(): array
    {
        return [
            'enabled' => false,
            'fake_in_tests' => false,
            'token' => CountingToken::class,
            'metrics' => ['orders.refunded' => ['type' => 'count', 'on' => OrderRefunded::class]],
        ];
    }

    public function testDisabledBuffersNothingAndSaysSo(): void
    {
        Wire::standard();

        event(new OrderRefunded());
        self::assertFalse($this->app->resolved(CacheBucketStore::class), 'no store is built');

        self::assertSame(0, Artisan::call('wt15:flush'));
        self::assertStringContainsString('disabled', Artisan::output());
        self::assertSame(0, CountingToken::$calls, 'no token resolved by the flush');
        Http::assertNothingSent();
        foreach (['wt15:test', 'wt15:metrics'] as $command) {
            Artisan::call($command);
            self::assertStringContainsString('disabled', Artisan::output(), $command);
        }
        $this->reconfigure(['enabled' => true]);
        event(new OrderRefunded());
        self::assertSame(1, $this->counted('orders.refunded'));
    }

    public function testWithNothingConfiguredTheFlushDoesNothingAndLogsNothing(): void
    {
        $this->reconfigure(['enabled' => true, 'metrics' => [], 'buffer' => ['store' => 'db', 'prefix' => $this->prefix]]);
        $this->app['config']->set('cache.stores.db', ['driver' => 'database', 'table' => 'cache', 'connection' => null, 'lock_connection' => null]);
        Wire::standard();

        foreach (['wt15:flush', 'wt15:flush --scheduled'] as $command) {
            $exit = Artisan::call($command);
            self::assertSame(0, $exit, $command);
            self::assertStringContainsString('No metric configured', Artisan::output());
        }
        self::assertSame([], $this->logRecords(), 'no log record');
        self::assertFalse($this->app->resolved(CacheBucketStore::class), 'no store call');
        self::assertSame(0, CountingToken::$calls, 'no token call');
        Http::assertNothingSent();

        Artisan::call('wt15:test');
        self::assertStringContainsString('SQLite', Artisan::output());
        Artisan::call('wt15:metrics');
        self::assertStringContainsString('SQLite', Artisan::output());
    }
}
