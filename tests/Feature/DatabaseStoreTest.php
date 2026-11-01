<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Laravel\Tests\TestCase;
use WatchTower15\Metrics\StoreScope;

/** LB7: the first increment of a missing key on a `database` store over SQLite, through the bucket store, in one process. */
final class DatabaseStoreTest extends TestCase
{
    protected function defineMore($app): void
    {
        $app['config']->set('cache.stores.db', ['driver' => 'database', 'table' => 'cache', 'connection' => null, 'lock_connection' => null, 'lock_table' => 'cache_locks']);
    }

    public function testTheFirstIncrementOfAMissingKeyAddsThenIncrements(): void
    {
        Schema::create('cache', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::create('cache_locks', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        $store = new CacheBucketStore($this->app->make(Factory::class), 'db', $this->prefix, StoreScope::Fleet, 'database', ['k' => 'count']);

        $store->increment(7, 'k', 1, 3600);
        self::assertSame(['count' => ['k' => 1], 'level' => []], $store->read(7));

        $store->increment(7, 'k', 2, 3600);
        self::assertSame(3, $store->read(7)['count']['k']);
        self::assertSame(StoreScope::Fleet, $store->scope());
    }
}
