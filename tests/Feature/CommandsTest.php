<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Laravel\Tests\Fixtures\CountingToken;
use WatchTower15\Laravel\Tests\Fixtures\ThrowingToken;
use WatchTower15\Laravel\Tests\Support\Keys;
use WatchTower15\Laravel\Tests\Support\Wire;

/** LB10 (`wt15:metrics`) and LB11 (`wt15:test`), on Redis and with `Http::fake()`. */
final class CommandsTest extends PipeTestCase
{
    protected function wt15Config(): array
    {
        $config = parent::wt15Config();
        $config['api_key'] = Keys::apiKey();
        $config['logs_token'] = Keys::logs();
        $config['flush'] = ['heartbeat' => 'wt15.flush'];

        return $config;
    }

    /** @return array{int, string} */
    private function run1(string $command, array $options = []): array
    {
        $exit = Artisan::call($command, $options);

        return [$exit, Artisan::output()];
    }

    public function testMetricsListsTheRowsTheHeartbeatAndTheCapAndDiffsAgainstTheIngest(): void
    {
        Wire::standard(metrics: [['key' => 'orders.shipped', 'type' => 'level', 'status' => 'active']]);
        Wt15::increment('typo.key');

        [$exit, $output] = $this->run1('wt15:metrics', ['--max-metrics' => 1, '--format' => 'json', '--diff' => true]);

        self::assertSame(1, $exit, 'three declared keys are above --max-metrics=1');
        $json = json_decode($output, true);
        self::assertIsArray($json);
        self::assertSame(3, $json['declared']);
        $byKey = [];
        foreach ($json['metrics'] as $metric) {
            $byKey[$metric['key']] = $metric;
        }
        self::assertTrue($byKey['wt15.flush']['heartbeat']);
        self::assertSame('missing', $byKey['wt15.flush']['remote_status']);
        self::assertSame('type differs at WatchTower15', $byKey['orders.shipped']['note']);
        self::assertSame('active', $byKey['orders.shipped']['remote_status']);
        self::assertSame(['not_allowed' => 1], $json['pending_errors']);
        $gets = array_values(array_filter($this->sent(), static fn ($r): bool => $r->method() === 'GET'));
        self::assertCount(1, $gets);
        self::assertSame('Bearer ' . Keys::metrics(), $gets[0]->header('Authorization')[0] ?? null, 'a metric-only client');
    }

    public function testTheTableListsTheKeysAndAFailedDiffIsReadAsNotRead(): void
    {
        Wire::standard(get: static fn () => Wire::forbidden());

        [$exit, $output] = $this->run1('wt15:metrics', ['--diff' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('orders.shipped', $output);
        self::assertStringContainsString('wt15.flush (heartbeat)', $output);
        self::assertStringContainsString('remote: not read (', $output);

        [$exit, $output] = $this->run1('wt15:metrics', ['--diff' => true, '--format' => 'json']);
        self::assertSame(1, $exit);
        $json = json_decode($output, true);
        self::assertIsArray($json);
        self::assertStringStartsWith('not read (', (string) $json['remote']);
    }

    public function testTestReportsEveryCredentialAndNeverWrites(): void
    {
        Wire::standard(metrics: [['key' => 'a', 'type' => 'count', 'status' => 'active'], ['key' => 'b', 'type' => 'count', 'status' => 'pending']], max: 50, used: 2);

        [$exit, $output] = $this->run1('wt15:test');

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('API key: ok (ci key)', $output);
        self::assertStringContainsString('metric token: ok (bbbb)', $output);
        self::assertStringContainsString('active 1, pending 1', $output);
        self::assertStringContainsString('2 of 50 used', $output);
        self::assertStringContainsString('clock skew', $output);
        self::assertStringContainsString('log token: format valid; not checked remotely', $output);
        self::assertSame([], $this->posts(), 'no POST in any run');

        // The metric token's GET answers 403.
        Http::swap(new Factory());
        Wire::standard(get: static fn () => Wire::forbidden());
        [$exit, $output] = $this->run1('wt15:test');
        self::assertSame(1, $exit);
        self::assertStringContainsString('metric token: failed', $output);
    }

    public function testAMalformedApiKeyExitsTwoMaskedAndTheMetricTokenIsStillChecked(): void
    {
        $this->reconfigure(['api_key' => 'wt15ak_truncated']);
        Wire::standard();

        [$exit, $output] = $this->run1('wt15:test');

        self::assertSame(2, $exit);
        self::assertStringContainsString('API key: malformed (wt15ak_…)', $output);
        self::assertStringContainsString('WT15_API_KEY', $output);
        self::assertStringNotContainsString('truncated', $output);
        self::assertStringContainsString('metric token: ok', $output);
        self::assertNotEmpty(array_filter($this->sent(), static fn ($r): bool => $r->method() === 'GET' && str_ends_with($r->url(), '/api/metric-sources/ingest')));
    }

    public function testNoCredentialAtAllExitsTwoAndNamesARefusedStore(): void
    {
        $this->reconfigure(['api_key' => null, 'token' => '', 'logs_token' => null, 'buffer' => ['store' => 'db', 'prefix' => $this->prefix]]);
        $this->app['config']->set('cache.stores.db', ['driver' => 'database', 'table' => 'cache', 'connection' => null, 'lock_connection' => null]);
        $this->app['config']->set('database.default', 'testing');
        Wire::standard();

        [$exit, $output] = $this->run1('wt15:test');

        self::assertSame(2, $exit);
        self::assertStringContainsString('no credential configured', $output);
        self::assertStringContainsString('SQLite', $output);
        self::assertSame([], $this->sent());
    }

    public function testATokenThatThrowsIsNotResolvedAndTheOtherChecksStillRun(): void
    {
        $this->reconfigure(['token' => ThrowingToken::class]);
        Wire::standard();

        [$exit, $output] = $this->run1('wt15:test');

        self::assertSame(1, $exit);
        self::assertStringContainsString('metric token: not resolved (RuntimeException: the secrets store is down)', $output);
        self::assertStringContainsString('API key: ok', $output);
        $ingest = array_filter($this->sent(), static fn ($r): bool => str_ends_with($r->url(), '/api/metric-sources/ingest'));
        self::assertSame([], array_values($ingest), 'no ingest GET');
        self::assertNotEmpty(array_filter($this->sent(), static fn ($r): bool => str_ends_with($r->url(), '/v1/me')));
        self::assertSame(CountingToken::$calls, 0);
        self::assertInstanceOf(CacheBucketStore::class, $this->bucketStore());
    }
}
