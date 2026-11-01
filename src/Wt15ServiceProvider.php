<?php

declare(strict_types=1);

namespace WatchTower15\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use WatchTower15\Client;
use WatchTower15\Laravel\Binding\EventBinder;
use WatchTower15\Laravel\Config\Settings;
use WatchTower15\Laravel\Console\FlushCommand;
use WatchTower15\Laravel\Console\MetricsCommand;
use WatchTower15\Laravel\Console\TestCommand;
use WatchTower15\Laravel\Http\LaravelTransport;
use WatchTower15\Laravel\Log\LevelLogger;
use WatchTower15\Laravel\Logs\BufferedChannels;
use WatchTower15\Laravel\Logs\WatchTower15LogDriver;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Laravel\Metrics\ContainerTokenProvider;
use WatchTower15\Laravel\Metrics\FlushRunner;
use WatchTower15\Laravel\Metrics\Pipe;
use WatchTower15\Laravel\Support\CarbonClock;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Metrics\StoreScope;
use WatchTower15\Store\InMemoryStore;
use WatchTower15\Testing\FakeClient;

/**
 * The bridge (D8). `register()` makes bindings and resolves nothing. `boot()` publishes the config, adds the commands, registers one
 * listener per configured event name, the scheduler's entry and, only when asked, the after-response flush; it resolves no
 * `Client`, `Aggregator` or store, makes no cache, HTTP or token call and writes no log.
 *
 * Lifetimes (D9): singletons hold no state and no container; everything that touches a request is scoped, which Octane drops per
 * request and a queue worker per job.
 */
final class Wt15ServiceProvider extends ServiceProvider
{
    /** The minute this process last ran the after-response flush (D6 b): a Carbon minute, so a test's `travel()` moves it. */
    private static ?int $afterResponseMinute = null;

    public function register(): void
    {
        // The package's own config beneath the application's, so the environment variables it names (`WT15_LOGS_TOKEN`, …) work with no
        // published file. Settings still merges `buffer`, `flush` and `log` key by key.
        $this->mergeConfigFrom(__DIR__ . '/../config/wt15.php', 'wt15');
        // One instance for every clone of the application Octane makes (D10): a plain object with no I/O.
        $this->app->instance(BufferedChannels::class, new BufferedChannels());
        $this->app->singleton(LaravelTransport::class);
        $this->app->singleton(ContainerTokenProvider::class);
        $this->app->singleton(ClientFactory::class);

        $this->app->scoped(Settings::class, static function (Application $app): Settings {
            $raw = $app->make(Repository::class)->get('wt15', []);

            return new Settings(is_array($raw) ? $raw : []);
        });
        $this->app->scoped(Pipe::class, static fn (Application $app): Pipe => new Pipe(
            $app->make(Settings::class),
            $app->make(Repository::class),
            $app,
        ));
        $this->app->scoped(LevelLogger::class, static fn (Application $app): LevelLogger => new LevelLogger($app->make(Pipe::class)));
        $this->app->scoped('wt15.metrics.logger', static fn (Application $app): LevelLogger => new LevelLogger($app->make(Pipe::class), true));

        // The facade's Client: all three credentials and both URLs. A malformed one throws the core's ConfigurationException here,
        // to the caller of Wt15::client() alone.
        $this->app->scoped(Client::class, static function (Application $app): Client {
            $settings = $app->make(Settings::class);

            return $app->make(ClientFactory::class)->make(
                $settings->apiKey(),
                $app->make(ContainerTokenProvider::class),
                $settings->logsToken(),
                $settings->apiUrl(),
                $settings->ingestUrl(),
                $app->make(LevelLogger::class),
            );
        });

        // The aggregator's own Client: the metric token and the ingest URL alone, so a malformed API key or log token never
        // stops the counting (D8). Under APP_ENV=testing it is a fake unless wt15.fake_in_tests is false (D12).
        $this->app->scoped('wt15.metrics.client', static function (Application $app): Client {
            $pipe = $app->make(Pipe::class);
            if ($app->environment('testing') && $pipe->settings()->fakeInTests()) {
                return new FakeClient();
            }

            return $app->make(ClientFactory::class)->make(
                null,
                $app->make(ContainerTokenProvider::class),
                null,
                null,
                $pipe->ingestProblem() === null ? $pipe->settings()->ingestUrl() : null,
                $app->make(LevelLogger::class),
            );
        });

        // The log channels' Client: the logs token and the ingest URL alone (D9). A malformed token or a refused URL throws the core's
        // ConfigurationException here, which the log shipper catches and counts as `config`.
        $this->app->scoped('wt15.logs.client', static function (Application $app): Client {
            $settings = $app->make(Settings::class);
            if ($app->environment('testing') && $settings->fakeInTests()) {
                return new FakeClient();
            }

            return $app->make(ClientFactory::class)->make(null, null, $settings->logsToken(), null, $settings->ingestUrl(), $app->make(LevelLogger::class));
        });

        $this->app->scoped(CacheBucketStore::class, static function (Application $app): CacheBucketStore {
            $pipe = $app->make(Pipe::class);
            $store = $pipe->store();
            $allow = $pipe->validated()->allowList();
            if ($pipe->validated()->heartbeat !== null) {
                $allow[$pipe->validated()->heartbeat] = 'count';
            }

            return new CacheBucketStore(
                $app->make(CacheFactory::class),
                $pipe->settings()->bufferStore(),
                $pipe->settings()->bufferPrefix(),
                $store->scope ?? StoreScope::Fleet,
                $store->driver ?? '',
                $allow,
            );
        });

        $this->app->scoped(Aggregator::class, static function (Application $app): Aggregator {
            $pipe = $app->make(Pipe::class);
            $settings = $pipe->settings();
            $accepted = $pipe->store()->accepted();
            $logger = $app->make('wt15.metrics.logger');
            $client = $app->make('wt15.metrics.client');
            if (! $client instanceof Client) {
                throw new \LogicException('The WatchTower15 metrics client is not bound.');
            }

            return new Aggregator(
                client: $client,
                store: $accepted ? $app->make(CacheBucketStore::class) : new InMemoryStore(),
                allowList: $pipe->validated()->allowList(),
                heartbeat: $pipe->validated()->heartbeat,
                enabled: $accepted,
                maxBuckets: $settings->maxBuckets(),
                maxRequests: $settings->maxRequests(),
                timeout: $settings->timeout(),
                connectTimeout: 1.0,
                logger: $logger instanceof LevelLogger ? $logger : null,
                clock: new CarbonClock(),
            );
        });

        $this->app->scoped(Wt15Manager::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/wt15.php' => $this->app->configPath('wt15.php')], 'wt15-config');

        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class, MetricsCommand::class, FlushCommand::class]);
        }

        $raw = $this->app->make(Repository::class)->get('wt15', []);
        $settings = new Settings(is_array($raw) ? $raw : []);

        // Listeners for the configured names only (D4). The rows are checked from configuration alone, so booting does no I/O.
        $pipe = new Pipe($settings, $this->app->make(Repository::class), $this->app);
        EventBinder::register($this->app->make(Dispatcher::class), $pipe->validated()->eventNames());

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($settings): void {
            if ($settings->schedule()) {
                $schedule->command('wt15:flush --scheduled')->everyMinute()->withoutOverlapping(5)->onOneServer()->runInBackground();
            }
        });

        $this->bootLogs();

        if ($settings->afterResponse()) {
            $this->app->terminating(static function (Application $app): void {
                self::flushAfterResponse($app);
            });
        }
    }

    /**
     * The `watchtower15` log driver and the points where a channel's buffered lines are sent (D9, D10). Registering resolves nothing:
     * the driver is added when the log manager is first resolved.
     */
    private function bootLogs(): void
    {
        $this->callAfterResolving('log', static function (mixed $manager, Application $app): void {
            if ($manager instanceof LogManager) {
                $manager->extend('watchtower15', fn (Application $app, array $config) => (new WatchTower15LogDriver())($app, $config));
            }
        });
        $this->app->terminating(static function (Application $app): void {
            self::flushLogs($app);
        });
        $events = $this->app->make(Dispatcher::class);
        foreach ([JobProcessed::class, JobFailed::class, JobReleasedAfterException::class] as $event) {
            $events->listen($event, static function (object $job): void {
                $connection = $job->connectionName ?? null;
                if (is_string($connection) && self::isSync($connection)) {
                    return;
                }
                self::flushLogs(null);
            });
        }
        $events->listen(WorkerStopping::class, static function (): void {
            self::flushLogs(null);
        });
    }

    /** A `sync` job runs inside the request that dispatched it: its lines go with that request's. */
    private static function isSync(string $connection): bool
    {
        $config = Container::getInstance()->make(Repository::class);
        $driver = $config->get('queue.connections.' . $connection . '.driver');

        return $driver === 'sync' || ($driver === null && $connection === 'sync');
    }

    private static function flushLogs(?Application $app): void
    {
        try {
            $channels = ($app ?? Container::getInstance())->make(BufferedChannels::class);
            if ($channels instanceof BufferedChannels) {
                $channels->flushAll();
            }
        } catch (\Throwable) {
            // Nothing reaches the application.
        }
    }

    /**
     * Clears the after-response callback's last-called minute (a test seam: PHPUnit runs every case in one process).
     *
     * @internal
     */
    public static function resetAfterResponseMinute(): void
    {
        self::$afterResponseMinute = null;
    }

    /**
     * D6 b: after the response, at most once a minute per process (and, through the core's claim, per store). It returns at once under
     * the console, while the bridge is off, with nothing configured, or when D5's or D14's rule refuses; every Throwable is caught.
     */
    private static function flushAfterResponse(Application $app): void
    {
        try {
            if ($app->runningInConsole()) {
                return;
            }
            $pipe = $app->make(Pipe::class);
            if (! $pipe->settings()->enabled() || ! $pipe->hasMetrics()) {
                return;
            }
            $minute = intdiv(Carbon::now()->getTimestamp(), 60);
            if (self::$afterResponseMinute === $minute) {
                return;
            }
            self::$afterResponseMinute = $minute;
            if ($pipe->refusal() !== null) {
                return;
            }
            FlushRunner::run(true);
        } catch (\Throwable) {
            // Nothing reaches the response.
        }
    }
}
