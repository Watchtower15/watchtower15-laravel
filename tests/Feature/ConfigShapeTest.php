<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Laravel\Tests\Fixtures\CountingToken;
use WatchTower15\Laravel\Tests\Fixtures\OrderRefunded;
use WatchTower15\Laravel\Tests\Support\Wire;

/** LB17: the closed config shape, on a `database` store over MySQL (config only) and on Redis. */
final class ConfigShapeTest extends PipeTestCase
{
    protected function wt15Config(): array
    {
        $config = parent::wt15Config();
        $config['token'] = CountingToken::class;

        return $config;
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        return [
            'orders.ok' => ['type' => 'count', 'auto_create' => true, 'unit' => 'orders'],
            'orders.agg' => ['type' => 'level', 'aggregate' => 'max', 'on' => OrderRefunded::class, 'value' => 'amount'],
            'orders.nocommit' => ['type' => 'count', 'after_commit' => false],
        ];
    }

    public function testRowsAreDisabledWithReasonsAndAMysqlStoreThatCannotBeReadIsNamed(): void
    {
        $this->reconfigure(['metrics' => $this->rows(), 'flush' => ['heartbeat' => 'bad key!'], 'buffer' => ['store' => 'db', 'prefix' => $this->prefix]]);
        $this->app['config']->set('cache.stores.db', ['driver' => 'database', 'table' => 'cache', 'connection' => 'mysql', 'lock_connection' => 'mysql']);
        $this->app['config']->set('database.connections.mysql', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => '']);

        $exit = Artisan::call('wt15:metrics');
        $output = Artisan::output();

        self::assertSame(1, $exit);
        self::assertStringContainsString('orders.ok', $output);
        self::assertStringContainsString('Disabled: orders.agg: aggregate: only last is supported', $output);
        self::assertStringContainsString('Disabled: orders.nocommit: after_commit: false is not allowed on the database cache store', $output);
        self::assertStringContainsString('Disabled: flush.heartbeat: not a valid metric key', $output);
        self::assertStringContainsString('Pending error counters: not read (', $output);
    }

    public function testOnRedisTheBadHeartbeatAndTheMaxRowAreNamedInTheRecordAndTheCoreSeesNone(): void
    {
        $this->reconfigure(['metrics' => $this->rows(), 'flush' => ['heartbeat' => 'bad key!']]);
        Wire::standard();
        Wt15::increment('orders.ok');
        Wt15::increment('typo.key');
        $this->closeBucket();

        self::assertSame(0, Artisan::call('wt15:flush'));

        $points = $this->postedPoints();
        self::assertCount(1, $points, 'no heartbeat point');
        self::assertSame('orders.ok', $points[0]['metric'] ?? null);
        $records = $this->logRecords();
        self::assertCount(1, $records, 'exactly one record: the core\'s');
        self::assertSame(1, $records[0]->context['errors']['not_allowed'] ?? null);
        self::assertSame([], $records[0]->context['config_problems'] ?? null, 'the bad heartbeat never reached the core');
        $problems = $records[0]->context['wt15_config_problems'] ?? [];
        self::assertIsArray($problems);
        self::assertCount(2, $problems);
        self::assertStringStartsWith('orders.agg: ', (string) $problems[0]);
        self::assertSame('flush.heartbeat: not a valid metric key', $problems[1]);
    }

    public function testWithNoValidRowTheFlushPrintsTheProblemsAndMakesNoCall(): void
    {
        $this->reconfigure(['metrics' => ['orders.agg' => $this->rows()['orders.agg']], 'flush' => ['heartbeat' => 'bad key!']]);
        Wire::standard();

        self::assertSame(0, Artisan::call('wt15:flush'));
        $output = Artisan::output();

        self::assertStringContainsString('No metric configured', $output);
        self::assertStringContainsString('Configuration problem: orders.agg:', $output);
        self::assertStringContainsString('Configuration problem: flush.heartbeat:', $output);
        self::assertCount(1, $this->logRecords(), 'one record of the problems');
        self::assertFalse($this->app->resolved(CacheBucketStore::class), 'no store call');
        self::assertSame(0, CountingToken::$calls, 'no token call');
        Http::assertNothingSent();
    }

    public function testALevelOrChannelThePsr3LoggerCannotUseFallsBackAndIsNamed(): void
    {
        $this->reconfigure(['metrics' => ['orders.ok' => ['type' => 'count']], 'log' => ['level' => 'warn', 'channel' => 'nope']]);
        Wire::standard();

        self::assertSame(0, Artisan::call('wt15:metrics'));
        $output = Artisan::output();
        self::assertStringContainsString('Disabled: log.level: not a PSR-3 level', $output);
        self::assertStringContainsString('Disabled: log.channel: not a configured channel', $output);

        self::assertSame(0, Artisan::call('wt15:flush'));

        $records = $this->logRecords();
        self::assertCount(1, $records, 'exactly one record, the bridge\'s own');
        self::assertSame(Level::Warning, $records[0]->level);
        self::assertSame(['log.level: not a PSR-3 level', 'log.channel: not a configured channel'], $records[0]->context['wt15_config_problems'] ?? null);
        self::assertSame([], array_filter($records, static fn ($r): bool => $r->level === Level::Emergency), 'no emergency record');
    }

    public function testTheConfigurationIsMergedKeyByKey(): void
    {
        $this->reconfigure(['flush' => ['schedule' => false, 'heartbeat' => 'wt15.flush']]);
        $settings = $this->app->make(\WatchTower15\Laravel\Config\Settings::class);

        self::assertSame(3.0, $settings->timeout());
        self::assertSame(60, $settings->maxBuckets());
        self::assertSame(10, $settings->maxRequests());
        self::assertFalse($settings->schedule());
        self::assertSame('wt15.flush', $settings->heartbeat());
    }
}
