<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Laravel\Support\CarbonClock;
use WatchTower15\Laravel\Tests\Fixtures\SpyCacheFactory;
use WatchTower15\Laravel\Tests\Fixtures\SpyCacheStore;
use WatchTower15\Laravel\Tests\Support\Keys;
use WatchTower15\Laravel\Tests\Support\Wire;
use WatchTower15\Laravel\Tests\TestCase;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Metrics\StoreScope;
use WatchTower15\Testing\FakeClient;

/** LB28 (the driver rule), LB31 (DynamoDB reads key by key), and the bucket store on a store with an `add()` of its own. */
final class StoreTest extends TestCase
{
    protected function wt15Config(): array
    {
        return [
            'fake_in_tests' => false,
            'token' => Keys::metrics(),
            'metrics' => ['orders.shipped' => ['type' => 'count']],
        ];
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedStores(): iterable
    {
        yield 'database over sqlite (the skeleton)' => [['driver' => 'database', 'table' => 'cache', 'connection' => null, 'lock_connection' => null], 'SQLite'];
        yield 'file' => [['driver' => 'file', 'path' => '/tmp'], 'file cache store'];
        yield 'apc' => [['driver' => 'apc'], 'apc cache store'];
        yield 'null' => [['driver' => 'null'], 'not supported'];
        yield 'an unconfigured store' => [[], 'not configured'];
    }

    /** @param array<string, mixed> $definition */
    #[DataProvider('refusedStores')]
    public function testARefusedStoreDisablesThePipeWithItsReason(array $definition, string $reason): void
    {
        $this->app['config']->set('cache.stores.refused', $definition);
        $this->reconfigure(['buffer' => ['store' => 'refused', 'prefix' => $this->prefix]]);
        if ($definition === []) {
            $this->app['config']->offsetUnset('cache.stores.refused');
        }
        Wire::standard();

        self::assertSame(2, Artisan::call('wt15:flush'));
        self::assertStringContainsString($reason, Artisan::output());
        self::assertCount(1, $this->logRecords(), 'logged once');

        self::assertSame(0, Artisan::call('wt15:flush', ['--scheduled' => true]));
        self::assertCount(2, $this->logRecords());
        Http::assertNothingSent();

        Artisan::call('wt15:test');
        self::assertStringContainsString($reason, Artisan::output());
        self::assertSame(1, Artisan::call('wt15:metrics'));
        self::assertStringContainsString($reason, Artisan::output());
        self::assertFalse($this->app->make(Aggregator::class)->isEnabled());
    }

    public function testArrayOutsideTestsIsRefusedAndInsideTestsItIsAProcessStore(): void
    {
        Wire::standard();
        self::assertSame(StoreScope::Process, $this->bucketStore()->scope());
        self::assertSame(2, Artisan::call('wt15:flush'));
        self::assertStringContainsString('lives in one process', Artisan::output());

        $this->app['env'] = 'production';
        $this->app->forgetScopedInstances();
        self::assertSame(2, Artisan::call('wt15:flush'));
        $output = Artisan::output();
        self::assertStringContainsString('array cache store', $output);
        self::assertStringContainsString('allowed under APP_ENV=testing alone', $output);
    }

    public function testAStoreThatCannotBeBuiltIsCaughtByTheCommand(): void
    {
        if (extension_loaded('memcached')) {
            self::markTestSkipped('ext-memcached is loaded, so the store can be built');
        }
        $this->app['config']->set('cache.stores.mc', ['driver' => 'memcached', 'servers' => [['host' => '127.0.0.1', 'port' => 11211, 'weight' => 100]]]);
        $this->reconfigure(['buffer' => ['store' => 'mc', 'prefix' => $this->prefix]]);
        Wire::standard();

        self::assertSame(0, Artisan::call('wt15:flush', ['--scheduled' => true]));
        self::assertCount(1, $this->logRecords());
        self::assertSame(1, Artisan::call('wt15:flush'));
        self::assertStringContainsString('The flush failed', Artisan::output());
        Http::assertNothingSent();
    }

    public function testInstanceIdAndClaimOnceUseTheStoresOwnAddWithATtl(): void
    {
        $cache = new SpyCacheStore();
        $store = new CacheBucketStore(new SpyCacheFactory($cache), null, 'p', StoreScope::Fleet, 'redis', []);

        $id = $store->instanceId();
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $id);
        self::assertSame($id, $store->instanceId(), 'made once');
        self::assertTrue($store->claimOnce('tick:1', 120));
        self::assertFalse($store->claimOnce('tick:1', 120));

        $kinds = array_column($cache->writes, 0);
        self::assertNotContains('put', $kinds, 'never put()');
        foreach ($cache->writes as [$kind, $key, $ttl]) {
            self::assertSame('add', $kind);
            self::assertGreaterThan(0, $ttl, $key . ' has a TTL');
        }
    }

    public function testDynamoDbReadsKeyByKey(): void
    {
        $keys = [];
        for ($i = 0; $i < 150; $i++) {
            $keys['k' . $i] = 'count';
        }
        $cache = new SpyCacheStore();
        $store = new CacheBucketStore(new SpyCacheFactory($cache), null, 'p', StoreScope::Fleet, 'dynamodb', $keys);

        $store->read(5);

        self::assertCount(150, $cache->reads);
        self::assertFalse($cache->manyUsed, 'many() is a capped batch on DynamoDB');
    }

    public function testAThrottledReadStopsTheRunWithStoreAndKeepsTheBucket(): void
    {
        $cache = new SpyCacheStore();
        $store = new CacheBucketStore(new SpyCacheFactory($cache), null, 'p', StoreScope::Fleet, 'dynamodb', ['orders.shipped' => 'count']);
        $aggregator = new Aggregator(client: new FakeClient(), store: $store, allowList: ['orders.shipped' => 'count'], clock: new CarbonClock());
        $bucket = $this->bucket();
        $aggregator->increment('orders.shipped');
        $this->travel(65)->seconds();

        $cache->failGets = true;
        $report = $aggregator->flush();

        self::assertSame('store', $report->stopReason);
        $cache->failGets = false;
        self::assertSame(1, $store->read($bucket)['count']['orders.shipped'] ?? 0, 'the bucket is kept');
        self::assertInstanceOf(Repository::class, (new SpyCacheFactory($cache))->store());
    }
}
