<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Logs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use WatchTower15\Client;
use WatchTower15\Laravel\Config\Settings;
use WatchTower15\Laravel\Log\LevelLogger;
use WatchTower15\Laravel\Testing\Wt15Fake;
use WatchTower15\Laravel\Wt15Manager;
use WatchTower15\Logs\Breaker\BreakerStore;
use WatchTower15\Logs\Breaker\FileBreakerStore;
use WatchTower15\Logs\Breaker\InMemoryBreakerStore;
use WatchTower15\Logs\Monolog\WatchTower15Handler;

/**
 * The `watchtower15` log driver (D9): `Log::extend('watchtower15', …)` calls it with the channel's config and gets a Monolog logger
 * whose one handler buffers each request's, job's or command's lines and sends them after it. The client, the self-logger and the
 * breaker store are closures resolved at each flush through the container and never kept, so a handler that outlives the
 * request's scoped bindings (Octane, a queue worker) or a fake never holds a stale one.
 *
 * @internal
 */
final class WatchTower15LogDriver
{
    /**
     * @param array<array-key, mixed> $config
     */
    public function __invoke(Application $app, array $config): LoggerInterface
    {
        $name = is_string($config['name'] ?? null) ? $config['name'] : null;
        $level = self::level($config['level'] ?? 'debug');
        $bubble = ($config['bubble'] ?? true) !== false;
        $limit = is_numeric($config['buffer_limit'] ?? null) ? (int) $config['buffer_limit'] : 100;
        $environment = $app->environment();
        $logger = new Logger($name ?? (is_string($environment) ? $environment : 'production'), [$this->handler($app, $name, $level, $bubble, $limit)]);
        if (($config['replace_placeholders'] ?? false) === true) {
            $logger->pushProcessor(new PsrLogMessageProcessor());
        }

        return $logger;
    }

    private function handler(Application $app, ?string $stream, Level $level, bool $bubble, int $limit): HandlerInterface
    {
        if (! $app->make(Settings::class)->enabled()) {
            return new NullHandler($level);
        }
        $inner = new WatchTower15Handler(
            static fn (): Client => self::client(),
            $stream,
            static fn (): LoggerInterface => Container::getInstance()->make(LevelLogger::class),
            static fn (): BreakerStore => self::usesFake()
                ? new InMemoryBreakerStore()
                : new FileBreakerStore(Container::getInstance()->make(Application::class)->storagePath('framework/cache/wt15')),
            null,
            $level,
            $bubble,
        );

        return new TrackedBufferHandler($inner, $app->make(BufferedChannels::class), $limit, $level, $bubble);
    }

    /** A channel's `level`, as Monolog reads it (a name in any case, a number or a `Level`); `debug` for anything else. */
    private static function level(mixed $level): Level
    {
        try {
            if ($level instanceof Level) {
                return $level;
            }
            if (is_int($level)) {
                return Level::from($level);
            }
            if (is_string($level)) {
                return match (strtolower($level)) {
                    'emergency' => Level::Emergency,
                    'alert' => Level::Alert,
                    'critical' => Level::Critical,
                    'error' => Level::Error,
                    'warning' => Level::Warning,
                    'notice' => Level::Notice,
                    'info' => Level::Info,
                    default => Level::Debug,
                };
            }
        } catch (\ValueError) {
            // Falls back to debug.
        }

        return Level::Debug;
    }

    /** Whether lines go to a fake client (`Wt15::fake()`, or `testing` with `fake_in_tests`): decided without building the client. */
    private static function usesFake(): bool
    {
        $container = Container::getInstance();

        return $container->make(Wt15Manager::class) instanceof Wt15Fake
            || ($container->make(Application::class)->environment('testing') && $container->make(Settings::class)->fakeInTests());
    }

    private static function client(): Client
    {
        $container = Container::getInstance();
        $manager = $container->make(Wt15Manager::class);
        if ($manager instanceof Wt15Fake) {
            return $manager->client();
        }
        $client = $container->make('wt15.logs.client');
        if (! $client instanceof Client) {
            throw new \LogicException('The WatchTower15 logs client is not bound.');
        }

        return $client;
    }
}
