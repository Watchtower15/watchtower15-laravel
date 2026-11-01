<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\ConfigurationException;
use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Tests\Fixtures\CountingToken;
use WatchTower15\Laravel\Tests\Fixtures\EmptyToken;
use WatchTower15\Laravel\Tests\Fixtures\OrderRefunded;
use WatchTower15\Laravel\Tests\Fixtures\ThrowingGate;
use WatchTower15\Laravel\Tests\Fixtures\Widget;
use WatchTower15\Laravel\Tests\Support\Keys;
use WatchTower15\Laravel\Tests\Support\Wire;

/** LB3 (the flush half), LB6, LB8, LB20, LB26, LB29, LB32 and LB33: the bridge on Redis, through Laravel's HTTP client. */
final class PipeTest extends PipeTestCase
{
    protected function wt15Config(): array
    {
        $config = parent::wt15Config();
        $config['api_key'] = Keys::apiKey();
        $config['metrics'] += [
            'orders.refunded' => ['type' => 'count', 'on' => OrderRefunded::class],
            'orders.gated' => ['type' => 'count', 'on' => OrderRefunded::class, 'when' => ThrowingGate::class],
            'widgets.gated' => ['type' => 'count', 'on' => 'eloquent.created: ' . Widget::class, 'when' => ThrowingGate::class],
            'orders.bad' => ['type' => 'count', 'on' => OrderRefunded::class, 'when' => 'amount >> 3'],
        ];

        return $config;
    }

    public function testBootAndEventsLogNothingAndAMalformedRowIsNamedByTheFlushInOneRecord(): void
    {
        Wire::standard();
        self::assertSame([], $this->logRecords(), 'booting logs nothing');
        event(new OrderRefunded());
        self::assertSame([], $this->logRecords(), 'an event logs nothing');

        $this->artisan('wt15:flush')->assertExitCode(0);

        $records = $this->logRecords();
        self::assertCount(1, $records, 'the bridge writes the one record of the problems');
        self::assertSame(Level::Warning, $records[0]->level);
        $problems = $records[0]->context['wt15_config_problems'] ?? null;
        self::assertIsArray($problems);
        self::assertCount(1, $problems);
        self::assertStringStartsWith('orders.bad: ', (string) $problems[0]);
        self::assertStringNotContainsString('>>', json_encode($records[0]->context, JSON_THROW_ON_ERROR), 'no value in the record');
    }

    public function testAWhenThatThrowsCountsABindingErrorAndTheFlushReportsItOnce(): void
    {
        Wire::standard();

        event(new OrderRefunded());   // does not throw to the application

        self::assertSame(['binding' => 1], $this->bucketStore()->errors());
        self::assertSame([], $this->logRecords(), 'no log line at the event');

        $this->closeBucket();
        $this->artisan('wt15:flush')->assertExitCode(0);

        $records = $this->logRecords();
        self::assertCount(1, $records);
        $errors = $records[0]->context['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertSame(1, $errors['binding'] ?? null);
    }

    public function testABindingErrorInARolledBackTransactionCountsNothing(): void
    {
        try {
            DB::transaction(static function (): void {
                Widget::create(['amount' => 1]);

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        self::assertSame([], $this->bucketStore()->errors());
    }

    public function testTheMetricTokenIsResolvedAtTheFirstSendAndSentAsTheBearer(): void
    {
        $token = Keys::metrics('e');
        $this->reconfigure(['token' => $token]);
        Wire::standard();

        Wt15::increment('orders.shipped');
        $this->closeBucket();
        $this->artisan('wt15:flush')->assertExitCode(0);

        $posts = $this->posts();
        self::assertCount(1, $posts);
        self::assertSame('Bearer ' . $token, $posts[0]->header('Authorization')[0] ?? null);
    }

    public function testAnInvokableTokenIsNotCalledAtBootAndIsCalledAtTheFirstSend(): void
    {
        $this->reconfigure(['token' => CountingToken::class]);
        Wire::standard();
        self::assertSame(0, CountingToken::$calls, 'never at boot');

        Wt15::increment('orders.shipped');
        self::assertSame(0, CountingToken::$calls, 'not by a count');
        $this->closeBucket();
        $this->artisan('wt15:flush')->assertExitCode(0);

        self::assertGreaterThanOrEqual(1, CountingToken::$calls);
        self::assertSame('Bearer ' . Keys::metrics('d'), $this->posts()[0]->header('Authorization')[0] ?? null);
    }

    public function testAnEmptyTokenKeepsTheBucketsCountsAConfigErrorAndStopsTheRun(): void
    {
        foreach (['', EmptyToken::class] as $token) {
            $this->reconfigure(['token' => $token]);
            Wire::standard();
            $bucket = $this->bucket();
            Wt15::increment('orders.shipped');
            $this->closeBucket();

            $this->artisan('wt15:flush')->assertExitCode(1);

            self::assertCount(0, $this->posts(), 'nothing is sent');
            self::assertSame(1, $this->counted('orders.shipped', $bucket), 'the bucket is kept');
            $report = array_values(array_filter($this->logRecords(), static fn ($r): bool => ($r->context['errors']['config'] ?? 0) > 0));
            self::assertCount(1, $report, 'one report, with the config error');
            // The next case starts clean.
            $this->bucketStore()->forget($bucket);
            $this->app['log']->forgetChannel('spy');
            Http::fake();
        }
    }

    public function testAClassLikeStringNamingNoClassIsTheTokenItselfAndIsRefusedAsMalformed(): void
    {
        $this->reconfigure(['token' => 'Nope\\NotAClass']);
        Wire::standard();
        $bucket = $this->bucket();
        Wt15::increment('orders.shipped');
        $this->closeBucket();

        $this->artisan('wt15:flush')->assertExitCode(1);

        self::assertCount(0, $this->posts());
        self::assertSame(1, $this->counted('orders.shipped', $bucket));
    }

    public function testAWt15msStringNeverReachesTheAutoloader(): void
    {
        $seen = [];
        $loader = static function (string $class) use (&$seen): void {
            $seen[] = $class;
        };
        spl_autoload_register($loader, true, true);
        try {
            Wire::standard();
            Wt15::increment('orders.shipped');
            $this->closeBucket();
            $this->artisan('wt15:flush')->assertExitCode(0);
        } finally {
            spl_autoload_unregister($loader);
        }

        self::assertCount(1, $this->posts());
        self::assertNotContains(Keys::metrics(), $seen);
    }

    public function testAnInvokableIsAskedAgainAfterARefusalAndTheKeptBucketIsSent(): void
    {
        $this->reconfigure(['token' => CountingToken::class]);
        $answers = [Wire::forbidden(), null];
        Wire::standard(static function () use (&$answers) {
            return array_shift($answers) ?? Wire::accepted(1);
        });
        Wt15::increment('orders.shipped');
        $this->closeBucket();

        $this->artisan('wt15:flush')->assertExitCode(1);
        $afterFirst = CountingToken::$calls;
        self::assertGreaterThanOrEqual(1, $afterFirst);

        $this->travel(61)->seconds();
        $this->artisan('wt15:flush')->assertExitCode(0);

        self::assertGreaterThan($afterFirst, CountingToken::$calls, 'no bridge memo: the invokable is asked again');
        self::assertCount(2, $this->posts(), 'the kept bucket is sent at the next run');
    }

    public function testEveryCallIsSeenByLaravelsHttpClientAndAStrayRequestStopsTheRun(): void
    {
        Wire::standard();
        Wt15::increment('orders.shipped');
        $this->closeBucket();
        $this->artisan('wt15:flush')->assertExitCode(0);
        $this->artisan('wt15:test')->assertExitCode(0);
        Wt15::me();

        Http::assertSent(static fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/metric-sources/ingest'));
        Http::assertSent(static fn ($request): bool => $request->method() === 'GET' && str_ends_with($request->url(), '/api/metric-sources/ingest'));
        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/v1/me'));
    }

    public function testAStrayRequestIsATransportErrorThatKeepsTheBucketsAndThrowsNothing(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://other.test/*' => Http::response()]);
        $bucket = $this->bucket();
        Wt15::increment('orders.shipped');
        $this->closeBucket();

        $this->artisan('wt15:flush')->assertExitCode(1);

        self::assertSame(1, $this->counted('orders.shipped', $bucket));
        $records = $this->logRecords();
        self::assertCount(1, $records);
        self::assertSame('transport', $records[0]->context['stop_reason'] ?? null);
        self::assertSame(1, $records[0]->context['errors']['transport'] ?? null);
    }

    public function testAnUndeclaredKeyIsCountedAfterTheCommitAndNotBeforeIt(): void
    {
        $during = null;
        DB::transaction(function () use (&$during): void {
            Wt15::increment('typo.key');
            Wt15::gauge('typo.level', 1);
            $during = $this->bucketStore()->errors();
        });
        self::assertSame([], $during, 'no store write inside the customer\'s transaction');
        self::assertSame(['not_allowed' => 2], $this->bucketStore()->errors());

        try {
            DB::transaction(static function (): void {
                Wt15::increment('typo.key');
                Wt15::gauge('typo.level', 1);

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
            // Expected.
        }
        self::assertSame(['not_allowed' => 2], $this->bucketStore()->errors(), 'a rollback counts nothing');
    }

    public function testTheHeartbeatIsSentOnceAMinuteWithNoCounts(): void
    {
        $this->reconfigure(['flush' => ['heartbeat' => 'wt15.flush']]);
        Wire::standard();

        $this->artisan('wt15:flush')->assertExitCode(0);
        $this->travel(65)->seconds();
        $this->artisan('wt15:flush')->assertExitCode(0);

        $points = $this->postedPoints();
        self::assertCount(1, $points);
        self::assertSame('wt15.flush', $points[0]['metric'] ?? null);
        self::assertSame(1, $points[0]['value'] ?? null);
    }

    public function testAMalformedApiKeyNeverStopsTheCounting(): void
    {
        $this->reconfigure(['api_key' => 'wt15ak_truncated']);
        Wire::standard();
        Wt15::increment('orders.shipped');
        $this->closeBucket();

        $this->artisan('wt15:flush')->assertExitCode(0);
        self::assertCount(1, $this->posts());

        try {
            Wt15::me();
            self::fail('Wt15::me() should throw');
        } catch (ConfigurationException $e) {
            self::assertStringNotContainsString('truncated', $e->getMessage(), 'the key is masked');
        }
    }

    public function testTheSdksRecordsAreWrittenAtTheConfiguredLevelKeepingTheCoresLevel(): void
    {
        $this->reconfigure(['log' => ['level' => 'error']]);
        $answers = [Wire::unavailable()];
        Wire::standard(static fn () => Wire::forbidden(), me: static function () use (&$answers) {
            return array_shift($answers) ?? Wire::me();
        });
        Wt15::increment('orders.shipped');
        $this->closeBucket();
        $this->artisan('wt15:flush')->assertExitCode(1);

        $records = $this->logRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame('warning', $records[0]->context['wt15_level'] ?? null);

        // A retry on the facade's client: a 503 then a 200.
        Wt15::me();

        $retry = array_values(array_filter($this->logRecords(), static fn ($r): bool => ($r->context['wt15_level'] ?? null) === 'notice'));
        self::assertNotEmpty($retry, 'the retry line is written too');
        self::assertSame(Level::Error, $retry[0]->level);
    }

    public function testNothingIsThrownForAConnectionFailure(): void
    {
        Http::fake(static fn () => Http::failedConnection());
        Wt15::increment('orders.shipped');
        $this->closeBucket();

        $this->artisan('wt15:flush')->assertExitCode(1);

        self::assertSame(ApiConnectionException::class, ApiConnectionException::class);
    }
}
