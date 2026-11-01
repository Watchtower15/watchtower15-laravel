<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Orchestra\Testbench\TestCase as Orchestra;
use WatchTower15\Laravel\Config\ConfigValidator;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Laravel\Tests\Fixtures\CountingToken;
use WatchTower15\Laravel\Wt15ServiceProvider;

/**
 * The bridge in an Orchestra Testbench application: the `array` cache store, an in-memory SQLite database with a `widgets` table,
 * and a `spy` log channel that every SDK record goes to. A test sets `wt15Config()`, which is read when the application boots (the
 * bridge's listeners are registered then), or changes the configuration later with `reconfigure()`.
 */
abstract class TestCase extends Orchestra
{
    protected string $prefix = '';

    protected function setUp(): void
    {
        $this->prefix = 'wt15t' . bin2hex(random_bytes(5));
        Wt15ServiceProvider::resetAfterResponseMinute();
        ConfigValidator::flush();
        CountingToken::$calls = 0;
        CountingToken::$answer = '';
        parent::setUp();
        // A frozen clock inside a minute, so a test never crosses a bucket boundary by accident.
        $this->travelTo(Carbon::now()->startOfMinute()->addSeconds(10));
        Schema::create('widgets', static function (Blueprint $table): void {
            $table->id();
            $table->integer('amount')->default(0);
            $table->string('name')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    /**
     * @param Application $app
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [Wt15ServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('cache.default', 'array');
        $config->set('cache.stores.array', ['driver' => 'array', 'serialize' => false]);
        $config->set('cache.prefix', 'wt15test');
        $config->set('logging.default', 'spy');
        $config->set('logging.channels.spy', ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']);
        $config->set('wt15', array_replace_recursive(['buffer' => ['prefix' => $this->prefix]], $this->wt15Config()));
        $this->defineMore($app);
    }

    /**
     * @param Application $app
     */
    protected function defineMore($app): void
    {
    }

    /**
     * The `wt15` configuration the application boots with.
     *
     * @return array<string, mixed>
     */
    protected function wt15Config(): array
    {
        return [];
    }

    /**
     * Changes the `wt15` configuration after boot and drops what was resolved from the old one.
     *
     * @param array<string, mixed> $values merged over the current configuration, key by key at the top level
     */
    protected function reconfigure(array $values): void
    {
        $current = $this->app['config']->get('wt15', []);
        $this->app['config']->set('wt15', array_replace(is_array($current) ? $current : [], $values));
        $this->app->forgetScopedInstances();
    }

    /** The current minute's bucket number. */
    protected function bucket(): int
    {
        return intdiv(Carbon::now()->getTimestamp(), 60);
    }

    protected function bucketStore(): CacheBucketStore
    {
        return $this->app->make(CacheBucketStore::class);
    }

    /** What the bucket holds for one count key. */
    protected function counted(string $key, ?int $bucket = null): int
    {
        return $this->bucketStore()->read($bucket ?? $this->bucket())['count'][$key] ?? 0;
    }

    /**
     * Every record the `spy` log channel holds.
     *
     * @return list<LogRecord>
     */
    protected function logRecords(): array
    {
        $log = $this->app['log']->channel('spy');
        self::assertInstanceOf(Logger::class, $log);
        $handler = $log->getLogger()->getHandlers()[0];
        self::assertInstanceOf(TestHandler::class, $handler);

        return $handler->getRecords();
    }
}
