<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Binding;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use WatchTower15\Laravel\Config\MetricDefinition;
use WatchTower15\Laravel\Metrics\Pipe;
use WatchTower15\Laravel\Wt15Manager;

/**
 * One listener per event name some row configures, never `*` (D4). A listener holds no request data and no container: it reads
 * the settings and the rows when it fires and returns at once while the bridge is off.
 *
 * @internal
 */
final class EventBinder
{
    /**
     * @param list<string> $names
     */
    public static function register(Dispatcher $events, array $names): void
    {
        foreach ($names as $name) {
            $wildcard = str_contains($name, '*');
            $events->listen($name, static function (mixed ...$arguments) use ($name, $wildcard): void {
                self::fire($name, $wildcard, $arguments);
            });
        }
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    private static function fire(string $name, bool $wildcard, array $arguments): void
    {
        try {
            $container = Container::getInstance();
            $pipe = $container->make(Pipe::class);
            if (! $pipe->settings()->enabled()) {
                return;
            }
            $subject = $arguments[0] ?? null;
            if ($wildcard) {
                $payload = $arguments[1] ?? [];
                $subject = is_array($payload) ? ($payload[0] ?? null) : null;
            }
            $manager = $container->make(Wt15Manager::class);
            foreach ($pipe->validated()->rows as $row) {
                if (self::listensTo($row, $name)) {
                    self::handle($row, $subject, $manager);
                }
            }
        } catch (\Throwable) {
            // Nothing reaches the application.
        }
    }

    private static function listensTo(MetricDefinition $row, string $name): bool
    {
        return in_array($name, $row->on, true);
    }

    private static function handle(MetricDefinition $row, mixed $subject, Wt15Manager $manager): void
    {
        try {
            if ($row->when !== null && ! $row->when->evaluate($subject)) {
                return;
            }
            $raw = $row->value?->evaluate($subject);
            if ($row->type === 'count') {
                $value = $raw === null ? 1 : (is_numeric($raw) ? (int) $raw : null);
            } else {
                $value = is_numeric($raw) ? $raw + 0 : null;
            }
            if ($value === null) {
                $manager->countBindingError();

                return;
            }
        } catch (\Throwable) {
            $manager->countBindingError();

            return;
        }
        $manager->record($row, $value);
    }
}
