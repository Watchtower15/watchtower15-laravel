<?php

declare(strict_types=1);

namespace WatchTower15\Laravel;

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use WatchTower15\Client;
use WatchTower15\Laravel\Config\MetricDefinition;
use WatchTower15\Laravel\Metrics\Pipe;
use WatchTower15\Metrics\Aggregator;

/**
 * The facade's root (D10). `client()` is the core `Client`; `increment()` and `gauge()` count through the aggregator and never
 * throw; every other call goes to the `Client`.
 *
 * Every store write waits for the customer's commit (D4, D10): a declared row follows its `after_commit`, anything else
 * (an undeclared key's `not_allowed` count among it) always does, so no cache row is locked inside the customer's transaction
 * unless a row asks for it. Scoped.
 *
 * @mixin Client
 */
class Wt15Manager
{
    /** The service properties of the facade's `Client`, which a facade cannot proxy. */
    public function client(): Client
    {
        return Container::getInstance()->make(Client::class);
    }

    public function increment(string $key, int $by = 1): void
    {
        $this->count(function () use ($key, $by): void {
            $this->aggregator()->increment($key, $by);
        }, $key);
    }

    public function gauge(string $key, int|float $value): void
    {
        $this->count(function () use ($key, $value): void {
            $this->aggregator()->gauge($key, $value);
        }, $key);
    }

    /**
     * A value a binding computed for a declared row.
     *
     * @internal
     */
    public function record(MetricDefinition $row, int|float $value): void
    {
        if ($row->type === 'count') {
            $this->increment($row->key, (int) $value);
        } else {
            $this->gauge($row->key, $value);
        }
    }

    /**
     * A `when` or `value` threw: counted as `binding`, after the commit, so a rolled-back transaction counts nothing.
     *
     * @internal
     */
    public function countBindingError(): void
    {
        $this->afterCommit(function (): void {
            $aggregator = $this->aggregator();
            if ($aggregator->isEnabled()) {
                $aggregator->store()->addError('binding');
            }
        });
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->client()->{$method}(...$arguments);
    }

    protected function aggregator(): Aggregator
    {
        return Container::getInstance()->make(Aggregator::class);
    }

    /**
     * Counts nothing and throws nothing when the bridge is off; waits for the commit unless a declared row says not to.
     */
    private function count(\Closure $write, string $key): void
    {
        try {
            $pipe = Container::getInstance()->make(Pipe::class);
            if (! $pipe->settings()->enabled()) {
                return;
            }
            $row = $pipe->validated()->rows[$key] ?? null;
            $immediate = $row !== null && ! ($row->afterCommit ?? $pipe->settings()->afterCommit());
            if ($immediate) {
                $this->guarded($write);

                return;
            }
            $this->afterCommit($write);
        } catch (\Throwable) {
            // Nothing reaches the application.
        }
    }

    private function afterCommit(\Closure $callback): void
    {
        $guarded = function () use ($callback): void {
            $this->guarded($callback);
        };
        try {
            Container::getInstance()->make(DatabaseManager::class)->afterCommit($guarded);
        } catch (\Throwable) {
            $guarded();
        }
    }

    private function guarded(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable) {
            // Nothing reaches the application.
        }
    }
}
