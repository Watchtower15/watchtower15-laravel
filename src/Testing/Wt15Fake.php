<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Testing;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use WatchTower15\Laravel\Config\MetricDefinition;
use WatchTower15\Laravel\Metrics\Pipe;
use WatchTower15\Laravel\Wt15Manager;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Metrics\FlushReport;
use WatchTower15\Testing\FakeAggregator;
use WatchTower15\Testing\FakeClient;

/**
 * `Wt15::fake()` (D11): a manager over the core's `FakeAggregator` and `FakeClient`. Counters, gauges and API calls are recorded
 * and nothing is sent. The bridge's own rules stay in front of it: a count waits for the customer's commit (a rolled-back
 * transaction records nothing), and, as in production, a key that no row declares, or that a row declares as the other type, is
 * dropped before it is recorded.
 */
final class Wt15Fake extends Wt15Manager
{
    private readonly FakeAggregator $fake;

    public function __construct()
    {
        $this->fake = new FakeAggregator();
    }

    /**
     * Turns the bridge on for the test and drops what was resolved, so the fake sees the configuration as it is now.
     *
     * @internal
     */
    public function arm(): void
    {
        $container = Container::getInstance();
        $container->make(Repository::class)->set('wt15.enabled', true);
        $container->forgetScopedInstances();
    }

    public function client(): FakeClient
    {
        return $this->fake->client();
    }

    public function increment(string $key, int $by = 1): void
    {
        if ($this->declaredAs($key) === 'count') {
            parent::increment($key, $by);
        }
    }

    public function gauge(string $key, int|float $value): void
    {
        if ($this->declaredAs($key) === 'level') {
            parent::gauge($key, $value);
        }
    }

    /** @internal */
    public function countBindingError(): void
    {
    }

    /** The core's `flush()` of the fake: always a final flush, into its `FakeClient` only. */
    public function flush(): FlushReport
    {
        return $this->fake->flush();
    }

    public function assertIncremented(string $key, ?int $by = null): void
    {
        $this->fake->assertIncremented($key, $by);
    }

    public function assertNotIncremented(string $key): void
    {
        $this->fake->assertNotIncremented($key);
    }

    public function assertGaugeSet(string $key, int|float|null $value = null): void
    {
        $this->fake->assertGaugeSet($key, $value);
    }

    public function assertNothingRecorded(): void
    {
        $this->fake->assertNothingRecorded();
    }

    protected function aggregator(): Aggregator
    {
        return $this->fake;
    }

    /**
     * @return 'count'|'level'|null
     */
    private function declaredAs(string $key): ?string
    {
        $validated = Container::getInstance()->make(Pipe::class)->validated();
        $row = $validated->rows[$key] ?? null;
        if ($row instanceof MetricDefinition) {
            return $row->type;
        }

        return $key === $validated->heartbeat ? 'count' : null;
    }
}
