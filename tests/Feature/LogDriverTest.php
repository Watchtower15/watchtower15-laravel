<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\NullHandler;
use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Logs\BufferedChannels;
use WatchTower15\Laravel\Tests\Fixtures\ClosesTheChannel;
use WatchTower15\Laravel\Tests\Support\Keys;
use WatchTower15\Laravel\Tests\Support\Wire;
use WatchTower15\Testing\FakeClient;

/** LG16 to LG20, LG22 to LG25: the `watchtower15` channel's buffering and its flush points. */
final class LogDriverTest extends LogsTestCase
{
    public function testLg16OnePushAfterTheRequestWithTheLaravelUserAgent(): void
    {
        Log::channel('watchtower15')->error('x');
        self::assertSame([], $this->logPosts(), 'nothing before the request ends');

        $this->app->terminate();

        self::assertCount(1, $this->logPosts());
        $agent = $this->logPosts()[0]->header('User-Agent')[0] ?? '';
        self::assertStringContainsString('laravel/' . $this->app->version(), $agent);
        $lines = Wire::lines($this->logPosts()[0]);
        self::assertSame('error', $lines[0]['log_level']);
        self::assertSame('billing', $lines[0]['stream_name']);
    }

    public function testLg16AMiddlewareThatClosesTheChannelSendsBeforeTheResponseLeaves(): void
    {
        $this->app['config']->set('logging.default', 'stack');
        $this->app['config']->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['watchtower15']]);
        $sentWhenTheMiddlewareReturned = null;
        ClosesTheChannel::$after = static function () use (&$sentWhenTheMiddlewareReturned): void {
            Log::channel('watchtower15')->getLogger()->close();
            $sentWhenTheMiddlewareReturned = count(Http::recorded());
        };
        Route::middleware(ClosesTheChannel::class)->get('/pay', static function (): string {
            Log::error('Payment failed');

            return 'ok';
        });

        $this->get('/pay')->assertOk();

        self::assertSame(1, $sentWhenTheMiddlewareReturned, 'the push was made by the time the middleware returned');
        self::assertCount(1, $this->logPosts(), 'and none at terminating');
    }

    /** @return iterable<string, array{string}> */
    public static function jobEvents(): iterable
    {
        yield 'processed' => ['processed'];
        yield 'failed' => ['failed'];
        yield 'released after an exception' => ['released'];
        yield 'the worker stopping' => ['stopping'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jobEvents')]
    public function testLg17AJobEventSendsTheJobsLines(string $kind): void
    {
        Log::channel('watchtower15')->error('in a job');
        $job = $this->createStub(Job::class);

        event(match ($kind) {
            'processed' => new JobProcessed('redis', $job),
            'failed' => new JobFailed('redis', $job, new \RuntimeException('x')),
            'released' => new JobReleasedAfterException('redis', $job),
            default => new WorkerStopping(0),
        });

        self::assertCount(1, $this->logPosts());
    }

    public function testLg17ASyncJobsLinesGoWithTheRequestsAtTerminating(): void
    {
        $this->app['config']->set('queue.connections.inline', ['driver' => 'sync']);
        $job = $this->createStub(Job::class);

        foreach (['sync', 'inline'] as $connection) {
            Log::channel('watchtower15')->error('in a sync job on ' . $connection);
            event(new JobProcessed($connection, $job));
            self::assertCount(0, $this->logPosts(), $connection . ': none at the job event');
            $this->app->terminate();
            self::assertCount(1, $this->logPosts(), $connection . ': sent with the request\'s at terminating');
            Http::swap(new \Illuminate\Http\Client\Factory());
            Wire::logs();
        }
    }

    public function testLg18TwoRequestsOnClonesShareOneBufferedChannelsAndALogBuildLeavesNothingBehind(): void
    {
        $original = $this->app;
        $channels = $original->make(BufferedChannels::class);
        $serve = static function (string $line) use ($original): void {
            $clone = clone $original;
            Container::setInstance($clone);
            Facade::setFacadeApplication($clone);
            $clone->instance('app', $clone);
            $clone->instance(Container::class, $clone);
            Log::channel('watchtower15')->error($line);
            $clone->terminate();
        };

        $serve('first request');
        self::assertCount(1, $this->logPosts());
        $serve('second request');

        self::assertCount(2, $this->logPosts());
        self::assertSame('first request', Wire::lines($this->logPosts()[0])[0]['message']);
        self::assertSame('second request', Wire::lines($this->logPosts()[1])[0]['message']);
        self::assertSame(0, $channels->count());

        Container::setInstance($original);
        Facade::setFacadeApplication($original);
        for ($i = 0; $i < 100; $i++) {
            Log::build(['driver' => 'watchtower15', 'level' => 'debug'])->error('built ' . $i);
        }
        self::assertSame(100, $channels->count() === 0 ? 0 : $channels->count(), 'each built channel holds its line until the end');
        $original->terminate();

        self::assertCount(102, $this->logPosts());
        self::assertSame(0, $channels->count(), 'nothing is kept past the flush');
    }

    public function testLg19ADisabledBridgeBuildsANullHandler(): void
    {
        $this->reconfigure(['enabled' => false]);

        $logger = Log::build(['driver' => 'watchtower15', 'level' => 'debug']);
        $logger->error('nothing');
        $this->app->terminate();

        self::assertInstanceOf(NullHandler::class, $logger->getLogger()->getHandlers()[0]);
        self::assertSame([], $this->logPosts());
    }

    public function testLg19UnderTestingTheLinesGoToTheFakeClientAndNeverThroughHttp(): void
    {
        $this->reconfigure(['fake_in_tests' => true]);

        Log::channel('watchtower15')->error('kept');
        $this->app->terminate();

        $client = $this->app->make('wt15.logs.client');
        self::assertInstanceOf(FakeClient::class, $client);
        self::assertCount(1, $client->pushedLines());
        Http::assertNothingSent();
    }

    public function testLg20TheFakeRecordsLogLines(): void
    {
        $fake = Wt15::fake();

        Log::channel('watchtower15')->error('Payment failed');
        Log::channel('watchtower15')->info('Fine');

        $fake->assertLogged('error', 'Payment');
        $lines = $fake->loggedLines();
        self::assertCount(2, $lines);
        self::assertSame(['level' => 'error', 'message' => 'Payment failed', 'stream' => 'billing'], $lines[0]);
        Http::assertNothingSent();
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $fake->assertNothingLogged();
    }

    public function testLg20NothingRecordedCoversLogLines(): void
    {
        $fake = Wt15::fake();
        Log::channel('watchtower15')->error('x');

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $fake->assertNothingRecorded();
    }

    public function testLg22BootingWithAChannelConfiguredDoesNoIo(): void
    {
        self::assertDirectoryDoesNotExist($this->breakerDirectory());
        self::assertFalse($this->app->resolved('wt15.logs.client'));
        self::assertFalse($this->app->resolved(\WatchTower15\Metrics\Aggregator::class));
        Http::assertNothingSent();
    }

    public function testLg23AVolumeStopWritesTheBreakerFileAndTheNextRequestSendsNothing(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Wire::logs(static fn () => Wire::logStop(3600));
        Log::channel('watchtower15')->error('first');
        $this->app->terminate();
        self::assertCount(1, $this->logPosts());

        $files = glob($this->breakerDirectory() . '/wt15-logs-*.breaker') ?: [];
        self::assertCount(1, $files);
        self::assertStringContainsString(hash('sha256', Keys::logs()), $files[0]);

        Log::channel('watchtower15')->error('second');
        $this->app->terminate();
        self::assertCount(1, $this->logPosts(), 'the next request\'s lines are dropped with no request');
    }

    public function testLg24TheBufferLimitSendsDuringTheRequest(): void
    {
        $this->app['config']->set('logging.channels.watchtower15.buffer_limit', 2);
        for ($i = 0; $i < 5; $i++) {
            Log::channel('watchtower15')->error('line ' . $i);
        }
        self::assertCount(2, $this->logPosts(), 'two pushes of two during the request');
        $this->app->terminate();

        self::assertCount(3, $this->logPosts());
        self::assertSame([2, 2, 1], array_map(static fn (Request $r): int => count(Wire::lines($r)), $this->logPosts()));
    }

    public function testLg24WithAHoldOpenNothingIsPushed(): void
    {
        $this->app['config']->set('logging.channels.watchtower15.buffer_limit', 2);
        $store = new \WatchTower15\Logs\Breaker\FileBreakerStore($this->breakerDirectory());
        $store->open(hash('sha256', Keys::logs()), (int) (microtime(true) * 1000) + 60_000, 'held');

        for ($i = 0; $i < 5; $i++) {
            Log::channel('watchtower15')->error('line ' . $i);
        }
        $this->app->terminate();

        self::assertSame([], $this->logPosts());
        self::assertNotEmpty(array_filter($this->logRecords(), static fn ($r): bool => str_contains($r->message, 'held')));
    }

    public function testLg25TheStreamNameFollowsTheChannelsNameThroughAStack(): void
    {
        $this->app['config']->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['watchtower15']]);
        Log::channel('watchtower15')->error('direct');
        Log::channel('stack')->error('through a stack');
        $this->app->terminate();

        $streams = array_column($this->sentLines(), 'stream_name');
        self::assertSame(['billing', 'billing'], $streams);
    }

    public function testLg25WithNoNameTheEnvironmentsNameIsUsedByMonologButNoStreamIsForced(): void
    {
        $channel = Log::build(['driver' => 'watchtower15', 'level' => 'debug']);
        $channel->error('unnamed');
        $this->app->terminate();

        self::assertSame($this->app->environment(), $this->sentLines()[0]['stream_name']);
    }

    public function testLg25PlaceholdersAreReplacedOnlyWhereTheChannelSaysSo(): void
    {
        $replaced = Log::build(['driver' => 'watchtower15', 'name' => 'a', 'replace_placeholders' => true]);
        $plain = Log::build(['driver' => 'watchtower15', 'name' => 'b']);
        $replaced->error('User {user}', ['user' => 'ann']);
        $plain->error('User {user}', ['user' => 'ann']);
        $this->app->terminate();

        $messages = array_column($this->sentLines(), 'message');
        sort($messages);
        self::assertSame(['User ann {"user":"ann"}', 'User {user} {"user":"ann"}'], $messages);
    }

    public function testLg21ReportsGoToTheDefaultStackAndNeverBackIntoAWatchTower15Channel(): void
    {
        $file = sys_get_temp_dir() . '/wt15-lg21-' . bin2hex(random_bytes(4)) . '.log';
        $this->app['config']->set('logging.channels.single', ['driver' => 'single', 'path' => $file, 'level' => 'debug']);
        $this->app['config']->set('logging.channels.wt_a', ['driver' => 'watchtower15', 'name' => 'a']);
        $this->app['config']->set('logging.channels.wt_b', ['driver' => 'watchtower15', 'name' => 'b']);
        $this->app['config']->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['single', 'wt_a', 'wt_b']]);
        $this->app['config']->set('logging.default', 'stack');
        Http::swap(new \Illuminate\Http\Client\Factory());
        Wire::logs(static fn () => Wire::status(500));
        try {
            Log::error('first request');
            $this->app->terminate();

            $written = (string) file_get_contents($file);
            self::assertStringContainsString('WatchTower15 retry of', $written);
            self::assertStringContainsString('lines not sent: rejected_500 1', $written);
            self::assertStringContainsString('lines not sent: held 1', $written);
            self::assertCount(2, $this->logPosts(), 'the first channel pushed and retried; the second sent nothing');

            Log::error('second request');
            $this->app->terminate();

            self::assertCount(2, $this->logPosts(), 'no call while the hold stands');
            self::assertSame(2, substr_count((string) file_get_contents($file), 'lines not sent:'), 'no new report');
        } finally {
            @unlink($file);
        }
    }
}
