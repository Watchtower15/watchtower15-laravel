<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests;

use Illuminate\Foundation\Application;

/**
 * A test on SDK03's Redis service: the host comes from WT15_SDK_TEST_REDIS_HOST into `database.redis`'s `default` and `cache`
 * connections. With WT15_SDK_TEST_REQUIRE_SERVICES=1 a missing service fails the test, as the core's do.
 */
abstract class RedisTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (self::host() === null) {
            if (getenv('WT15_SDK_TEST_REQUIRE_SERVICES') === '1') {
                self::fail('WT15_SDK_TEST_REDIS_HOST is not set, and the services are required.');
            }
            self::markTestSkipped('no Redis service (WT15_SDK_TEST_REDIS_HOST)');
        }
        if (! extension_loaded('redis')) {
            if (getenv('WT15_SDK_TEST_REQUIRE_SERVICES') === '1') {
                self::fail('ext-redis is not loaded, and the services are required.');
            }
            self::markTestSkipped('ext-redis is not loaded');
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $redis = $this->app->make('redis');
            $redis->connection('cache')->flushdb();
            $redis->connection('default')->flushdb();
        }
        parent::tearDown();
    }

    /**
     * @param Application $app
     */
    protected function defineMore($app): void
    {
        $host = self::host() ?? '127.0.0.1';
        $config = $app['config'];
        $config->set('database.redis', [
            'client' => 'phpredis',
            'options' => ['prefix' => ''],
            'default' => ['host' => $host, 'port' => 6379, 'database' => 0],
            'cache' => ['host' => $host, 'port' => 6379, 'database' => 1],
        ]);
        $config->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default']);
        $config->set('cache.default', 'redis');
    }

    protected static function host(): ?string
    {
        $host = getenv('WT15_SDK_TEST_REDIS_HOST');

        return is_string($host) && $host !== '' ? $host : null;
    }
}
