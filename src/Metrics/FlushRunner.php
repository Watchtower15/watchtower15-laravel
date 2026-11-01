<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Metrics;

use Illuminate\Container\Container;
use WatchTower15\Laravel\Log\LevelLogger;
use WatchTower15\Metrics\Aggregator;
use WatchTower15\Metrics\FlushReport;

/**
 * One flush through the scoped aggregator (D6): the core's `flush()` or `flushIfDue()`, and the bridge's own problems in the run's
 * one record (D6 c). The core writes its record through the aggregator's logger and carries nothing of the bridge's problems;
 * when a run reaches the core, ends `flushed` and the core wrote nothing while the validator holds a problem, the bridge writes
 * that one record itself, so a run writes at most one record of its own.
 *
 * @internal
 */
final class FlushRunner
{
    public static function run(bool $ifDue): ?FlushReport
    {
        $container = Container::getInstance();
        $aggregator = $container->make(Aggregator::class);
        $logger = $container->make('wt15.metrics.logger');
        $before = $logger instanceof LevelLogger ? $logger->written() : 0;
        $report = $ifDue ? $aggregator->flushIfDue() : $aggregator->flush();
        if ($report !== null && $report->status === 'flushed' && $logger instanceof LevelLogger && $logger->written() === $before) {
            self::recordProblems($logger, $container->make(Pipe::class));
        }

        return $report;
    }

    /**
     * The one record of the bridge's own findings: the validator's problems (carried by the logger), or a refusal.
     */
    public static function recordProblems(LevelLogger $logger, Pipe $pipe, string $message = 'WatchTower15 metrics configuration has problems.'): void
    {
        if ($pipe->validated()->problems !== []) {
            $logger->warning($message);
        }
    }
}
