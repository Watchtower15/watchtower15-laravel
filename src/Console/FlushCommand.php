<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use WatchTower15\Laravel\Log\LevelLogger;
use WatchTower15\Laravel\Metrics\FlushRunner;
use WatchTower15\Laravel\Metrics\Pipe;
use WatchTower15\Laravel\Testing\Wt15Fake;
use WatchTower15\Laravel\Wt15Manager;
use WatchTower15\Metrics\FlushReport;
use WatchTower15\Metrics\StoreScope;

/**
 * One flush now (D6 a, D13): the checks of §3.4 in order, then the core's `flush()`, which takes its own lock. `--scheduled` is the
 * scheduler's: it exits 0 whatever the run reports, so `schedule:run` never sends a refused store or a WatchTower15 outage to
 * the application's exception handler.
 */
final class FlushCommand extends Command
{
    protected $signature = 'wt15:flush {--scheduled : Used by the scheduler: the run always exits 0}';

    protected $description = 'Send the closed minute buckets of the metrics pipe to WatchTower15 now';

    public function handle(): int
    {
        $scheduled = (bool) $this->option('scheduled');
        try {
            $code = $this->flush();
        } catch (\Throwable $e) {
            $this->error('The flush failed: ' . $e::class . ': ' . $e->getMessage());
            $this->record('WatchTower15 metrics flush failed.', ['exception' => $e::class . ': ' . $e->getMessage()]);
            $code = 1;
        }

        return $scheduled ? 0 : $code;
    }

    private function flush(): int
    {
        $container = Container::getInstance();
        $pipe = $container->make(Pipe::class);
        if (! $pipe->settings()->enabled()) {
            $this->line('WatchTower15 is disabled (wt15.enabled is false): nothing to flush.');

            return 0;
        }
        $manager = $container->make(Wt15Manager::class);
        if ($manager instanceof Wt15Fake) {
            $this->printReport($manager->flush());

            return 0;
        }
        if (! $pipe->hasMetrics()) {
            $this->line('No metric configured: nothing to flush.');
            $this->printProblems($pipe);
            foreach ($pipe->validated()->problems as $problem) {
                if (! str_starts_with($problem, 'log.')) {
                    // Every configured row, or the heartbeat, was disabled: the one record of the problems.
                    $this->record('WatchTower15 metrics configuration has problems.');
                    break;
                }
            }

            return 0;
        }
        $refusal = $pipe->refusal();
        if ($refusal === null && $pipe->store()->scope === StoreScope::Process) {
            $refusal = 'An in-memory store lives in one process; there is nothing to flush from here.';
        }
        if ($refusal !== null) {
            $this->error('Nothing was sent: ' . $refusal);
            $this->record('WatchTower15 metrics were not sent: ' . $refusal);
            $this->printProblems($pipe);

            return 2;
        }

        $report = FlushRunner::run(false);
        if ($report === null) {
            return 0;
        }
        $this->printReport($report);
        $this->printProblems($pipe);

        return $report->status === 'flushed' && $report->stopReason !== null && $report->stopReason !== 'max_requests' ? 1 : 0;
    }

    private function printReport(FlushReport $report): void
    {
        if ($report->status === 'disabled') {
            $this->line('WatchTower15 metrics are disabled: nothing to flush.');

            return;
        }
        if ($report->status === 'locked') {
            $this->line('Another flush is running: nothing done.');

            return;
        }
        $this->line(sprintf(
            'Flushed: %d bucket(s) sent, %d empty, %d dropped; %d request(s), %d point(s) accepted, %d chunk(s) dropped.',
            $report->bucketsSent,
            $report->bucketsEmpty,
            $report->bucketsDropped,
            $report->requests,
            $report->points,
            $report->chunksDropped,
        ));
        if ($report->rejected !== []) {
            $this->line('Rejected by the server: ' . json_encode($report->rejected));
        }
        if ($report->stopReason !== null) {
            $this->line('Stopped: ' . $report->stopReason);
        }
        if ($report->errors !== []) {
            $parts = [];
            foreach ($report->errors as $reason => $count) {
                $parts[] = $reason . ' ' . $count;
            }
            $this->line('Counted since the last flush: ' . implode(', ', $parts));
        }
    }

    private function printProblems(Pipe $pipe): void
    {
        foreach ($pipe->validated()->problems as $problem) {
            $this->line('Configuration problem: ' . $problem);
        }
    }

    /**
     * The run's one record, through the aggregator's own logger so it carries the validator's problems (D6 c). A logger that
     * throws is ignored.
     *
     * @param array<string, mixed> $context
     */
    private function record(string $message, array $context = []): void
    {
        try {
            $logger = Container::getInstance()->make('wt15.metrics.logger');
            if ($logger instanceof LevelLogger) {
                $logger->warning($message, $context);
            }
        } catch (\Throwable) {
            // Ignored.
        }
    }
}
