<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use WatchTower15\Laravel\ClientFactory;
use WatchTower15\Laravel\Log\LevelLogger;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Laravel\Metrics\ContainerTokenProvider;
use WatchTower15\Laravel\Metrics\Pipe;
use WatchTower15\Laravel\Support\UrlRule;

/**
 * Lists what the bridge counts (D13): every valid row, the heartbeat, the disabled rows with their reasons, the cache store's
 * verdict, and the error counters still pending (read, never subtracted). `--diff` adds each key's status and type at
 * WatchTower15. Exit 2 for `--diff` with an invalid `ingest_url`; else 1 when the store is refused, the counters or the remote read
 * could not be read, or the declared keys are above `--max-metrics`; else 0.
 */
final class MetricsCommand extends Command
{
    protected $signature = 'wt15:metrics {--diff : Add each key\'s status and type at WatchTower15} {--max-metrics= : Exit 1 above this many declared keys} {--format=table : table or json}';

    protected $description = 'List the metric keys the Laravel bridge counts';

    public function handle(): int
    {
        $container = Container::getInstance();
        $pipe = $container->make(Pipe::class);
        $settings = $pipe->settings();
        $validated = $pipe->validated();
        $store = $pipe->store();
        $json = $this->option('format') === 'json';
        $diff = (bool) $this->option('diff');
        $exit = 0;

        if ($diff && ($problem = $pipe->ingestProblem()) !== null) {
            $this->error(UrlRule::variable('ingestUrl') . ' is invalid: ' . $problem);

            return 2;
        }

        $pending = null;
        $pendingText = null;
        if (! $store->accepted()) {
            $pendingText = 'not read (store refused)';
            $exit = 1;
        } else {
            try {
                $pending = $container->make(CacheBucketStore::class)->errors();
            } catch (\Throwable $e) {
                $pendingText = 'not read (' . $e::class . ': ' . $e->getMessage() . ')';
                $exit = 1;
            }
        }

        $remote = null;
        $remoteText = null;
        if ($diff) {
            try {
                $client = $container->make(ClientFactory::class)->make(
                    null,
                    $container->make(ContainerTokenProvider::class),
                    null,
                    null,
                    $settings->ingestUrl(),
                    $container->make(LevelLogger::class),
                );
                $remote = [];
                foreach ($client->ingest->metrics->list()->metrics as $metric) {
                    $remote[$metric->key] = ['status' => $metric->status, 'type' => $metric->type];
                }
            } catch (\Throwable $e) {
                $remoteText = 'not read (' . $e::class . ': ' . $e->getMessage() . ')';
                $exit = 1;
            }
        }

        $entries = [];
        foreach ($validated->rows as $key => $row) {
            $entries[] = ['key' => $key, 'type' => $row->type, 'on' => $row->on, 'heartbeat' => false];
        }
        if ($validated->heartbeat !== null) {
            $entries[] = ['key' => $validated->heartbeat, 'type' => 'count', 'on' => [], 'heartbeat' => true];
        }
        $declared = count($entries);

        $max = $this->option('max-metrics');
        $overCap = is_numeric($max) && $declared > (int) $max;
        if ($overCap) {
            $exit = 1;
        }

        $lines = [];
        foreach ($entries as $entry) {
            $line = $entry + ['remote_status' => null, 'remote_type' => null, 'note' => null];
            if ($remote !== null) {
                $seen = $remote[$entry['key']] ?? null;
                $line['remote_status'] = $seen['status'] ?? 'missing';
                $line['remote_type'] = $seen['type'] ?? null;
                if ($seen !== null && $seen['type'] !== '' && $seen['type'] !== $entry['type']) {
                    $line['note'] = 'type differs at WatchTower15';
                }
            }
            $lines[] = $line;
        }

        if ($json) {
            $this->line((string) json_encode([
                'enabled' => $settings->enabled(),
                'store' => ['name' => $store->store, 'driver' => $store->driver, 'accepted' => $store->accepted(), 'reason' => $store->reason],
                'declared' => $declared,
                'metrics' => $lines,
                'disabled' => $validated->problems,
                'pending_errors' => $pending ?? $pendingText,
                'remote' => $remoteText,
                'over_max_metrics' => $overCap,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        if (! $settings->enabled()) {
            $this->line('WatchTower15 is disabled (wt15.enabled is false).');
        }
        $this->line('Cache store: ' . $store->store . ($store->driver !== null ? ' (' . $store->driver . ')' : ''));
        if (! $store->accepted()) {
            $this->line('Refused: ' . $store->reason);
        }
        $rows = [];
        foreach ($lines as $line) {
            $cells = [$line['key'] . ($line['heartbeat'] ? ' (heartbeat)' : ''), $line['type'], $line['on'] === [] ? '-' : implode(', ', $line['on'])];
            if ($diff) {
                $cells[] = $line['remote_status'] ?? '-';
                $cells[] = $line['remote_type'] ?? '-';
                $cells[] = $line['note'] ?? '';
            }
            $rows[] = $cells;
        }
        $this->table($diff ? ['Key', 'Type', 'Bound to', 'Remote status', 'Remote type', 'Note'] : ['Key', 'Type', 'Bound to'], $rows);
        if ($remoteText !== null) {
            $this->line('remote: ' . $remoteText);
        }
        foreach ($validated->problems as $problem) {
            $this->line('Disabled: ' . $problem);
        }
        if ($pending !== null) {
            $this->line('Pending error counters: ' . ($pending === [] ? 'none' : implode(', ', array_map(static fn (string $reason, int $count): string => $reason . ' ' . $count, array_keys($pending), $pending))));
        } else {
            $this->line('Pending error counters: ' . $pendingText);
        }
        if ($overCap) {
            $this->error($declared . ' declared keys is above --max-metrics=' . (string) $max);
        }

        return $exit;
    }
}
