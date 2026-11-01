<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Testing;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use PHPUnit\Framework\Assert;
use WatchTower15\Laravel\Config\MetricDefinition;
use WatchTower15\Laravel\Logs\BufferedChannels;
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
        $this->assertNothingLogged();
    }

    /**
     * The log lines the `watchtower15` channels sent into the fake, after flushing what they hold (what the request would send).
     *
     * @return list<array{level: string, message: string, stream: ?string}>
     */
    public function loggedLines(): array
    {
        $this->flushChannels();
        $lines = [];
        foreach ($this->fake->client()->pushedLines() as $line) {
            $lines[] = [
                'level' => is_string($line['log_level'] ?? null) ? $line['log_level'] : '',
                'message' => is_string($line['message'] ?? null) ? $line['message'] : '',
                'stream' => is_string($line['stream_name'] ?? null) ? $line['stream_name'] : null,
            ];
        }

        return $lines;
    }

    public function assertLogged(string $level, string $contains): void
    {
        foreach ($this->loggedLines() as $line) {
            if ($line['level'] === strtolower($level) && str_contains($line['message'], $contains)) {
                return;
            }
        }

        Assert::fail(sprintf('No %s log line containing "%s" was sent.', $level, $contains));
    }

    public function assertNothingLogged(): void
    {
        $count = count($this->loggedLines());
        if ($count > 0) {
            Assert::fail(sprintf('Expected no log line to be sent; %d were.', $count));
        }
    }

    private function flushChannels(): void
    {
        Container::getInstance()->make(BufferedChannels::class)->flushAll();
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
