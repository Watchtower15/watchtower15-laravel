<?php

declare(strict_types=1);

namespace WatchTower15\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
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

        if ($settings->afterResponse()) {
            $this->app->terminating(static function (Application $app): void {
                self::flushAfterResponse($app);
            });
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
