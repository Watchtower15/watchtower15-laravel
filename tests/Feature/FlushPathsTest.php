<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Http;
use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Laravel\Tests\Support\SpiesOnTheStore;
use WatchTower15\Laravel\Tests\Support\Wire;

/** LB25: the after-response flush, on Redis. */
final class FlushPathsTest extends PipeTestCase
{
    use SpiesOnTheStore;

    protected function wt15Config(): array
    {
        $config = parent::wt15Config();
        $config['flush'] = ['after_response' => true, 'heartbeat' => 'wt15.flush'];

        return $config;
    }

    /**
     * Octane reports false for the console: the after-response callback is for requests.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineMore($app): void
    {
        parent::defineMore($app);
        (new \ReflectionProperty($app, 'isRunningInConsole'))->setValue($app, false);
    }

    public function testAfterTheResponseTheFlushRunsAtMostOnceAMinute(): void
    {
        Wire::standard();
        $spy = $this->spyStore();
        Wt15::increment('orders.shipped');
        $this->closeBucket();

        $this->app->terminate();
        $this->app->terminate();

        self::assertCount(1, $this->posts());
        self::assertSame(1, $spy->count('claimOnce'), 'one claim in the minute');

        Wt15::increment('orders.shipped');
        $this->travel(70)->seconds();
        $this->app->terminate();

        self::assertCount(2, $this->posts(), 'the next minute flushes again');
    }

    public function testAFlushThatThrowsNeverReachesTheCaller(): void
    {
        $this->app->scoped(Aggregator::class, static function (): Aggregator {
            throw new \RuntimeException('boom');
        });

        $this->app->terminate();

        self::assertTrue(true, 'nothing was thrown');
    }

    public function testWithNothingConfiguredTheCallbackMakesNoStoreCall(): void
    {
        $this->reconfigure(['metrics' => [], 'flush' => ['after_response' => true, 'heartbeat' => null]]);

        $this->app->terminate();

        self::assertFalse($this->app->resolved(CacheBucketStore::class));
        Http::assertNothingSent();
    }

    public function testAnInvalidIngestUrlIsSilentAfterTheResponse(): void
    {
        $this->reconfigure(['ingest_url' => 'http://api.example.com']);
        Wire::standard();
        Wt15::increment('orders.shipped');
        $this->closeBucket();

        $this->app->terminate();

        self::assertSame([], $this->sent());
        self::assertSame([], $this->logRecords());
    }
}
