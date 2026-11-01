<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use WatchTower15\Client;
use WatchTower15\Credentials\CredentialFormat;
use WatchTower15\Laravel\ClientFactory;
use WatchTower15\Laravel\Log\LevelLogger;
use WatchTower15\Laravel\Metrics\ContainerTokenProvider;
use WatchTower15\Laravel\Metrics\Pipe;
use WatchTower15\Laravel\Support\UrlRule;

/**
 * Checks every credential the application configured (D13), each on a `Client` of its own that holds that credential and its
 * URL alone: the API key with `me()`, the metric token with the source's listing, the log token by its format (a log push is a
 * write). It never writes and makes no store call: `wt15:metrics` is the check that the store is reachable. Exit 2 when no
 * credential is configured or one is malformed (or a URL is invalid); else 1 when a check failed, was not reached or the store
 * is refused; else 0.
 */
final class TestCommand extends Command
{
    protected $signature = 'wt15:test';

    protected $description = 'Check the credentials and URLs of the WatchTower15 Laravel bridge';

    public function handle(): int
    {
        $container = Container::getInstance();
        $pipe = $container->make(Pipe::class);
        $settings = $pipe->settings();
        $factory = $container->make(ClientFactory::class);
        $logger = $container->make(LevelLogger::class);
        $malformed = false;
        $failed = false;

        if (! $settings->enabled()) {
            $this->line('WatchTower15 is disabled (wt15.enabled is false); the checks run all the same.');
        }

        $apiProblem = $pipe->apiProblem();
        $ingestProblem = $pipe->ingestProblem();
        foreach ([['apiUrl', $apiProblem], ['ingestUrl', $ingestProblem]] as [$argument, $problem]) {
            if ($problem !== null) {
                $this->line(UrlRule::variable($argument) . ' is invalid: ' . $problem);
                $malformed = true;
            }
        }

        $configured = 0;

        // The API key.
        $apiKey = $settings->apiKey();
        if ($apiKey !== null) {
            $configured++;
            if (! CredentialFormat::isApiKey($apiKey)) {
                $this->line('API key: malformed (' . CredentialFormat::mask($apiKey) . '), set in WT15_API_KEY');
                $malformed = true;
            } elseif ($apiProblem !== null) {
                $this->line('API key: format valid; not checked: ' . UrlRule::variable('apiUrl') . ' is invalid');
            } else {
                try {
                    $client = $factory->make($apiKey, null, null, $settings->apiUrl(), null, $logger);
                    $me = $client->me();
                    $this->line(sprintf(
                        'API key: ok (%s), %s in %s, scopes %s, %s%s',
                        $me->keyName,
                        $me->userName === '' ? 'user' : $me->userName,
                        $me->organisationName,
                        $me->scopes === [] ? 'none' : implode(' ', $me->scopes),
                        $me->keyExpiresAt === null ? 'no expiry' : 'expires ' . $me->keyExpiresAt->format('Y-m-d'),
                        $this->skew($client),
                    ));
                } catch (\Throwable $e) {
                    $this->line('API key: failed (' . $e::class . ': ' . $e->getMessage() . ')');
                    $failed = true;
                }
            }
        }

        // The metric token: resolved inside a catch, since an invokable is the application's own code.
        $token = null;
        try {
            $token = $container->make(ContainerTokenProvider::class)->token();
        } catch (\Throwable $e) {
            $configured++;
            $this->line('metric token: not resolved (' . $e::class . ': ' . $e->getMessage() . ')');
            $failed = true;
        }
        if ($token !== null && $token !== '') {
            $configured++;
            if (! CredentialFormat::isMetricsToken($token)) {
                $this->line('metric token: malformed (' . CredentialFormat::mask($token) . '), set in WT15_METRICS_TOKEN');
                $malformed = true;
            } elseif ($ingestProblem !== null) {
                $this->line('metric token: format valid; not checked: ' . UrlRule::variable('ingestUrl') . ' is invalid');
            } else {
                try {
                    $client = $factory->make(null, $token, null, null, $settings->ingestUrl(), $logger);
                    $listing = $client->ingest->metrics->list();
                    $counts = [];
                    foreach ($listing->metrics as $metric) {
                        $counts[$metric->status] = ($counts[$metric->status] ?? 0) + 1;
                    }
                    ksort($counts);
                    $this->line(sprintf(
                        'metric token: ok (%s), %d metric(s) [%s], %s of %s used%s',
                        substr(CredentialFormat::mask($token), -4),
                        count($listing->metrics),
                        $counts === [] ? 'none' : implode(', ', array_map(static fn (string $status, int $n): string => $status . ' ' . $n, array_keys($counts), $counts)),
                        $listing->used === null ? '?' : (string) $listing->used,
                        $listing->maxMetrics === null ? '?' : (string) $listing->maxMetrics,
                        $this->skew($client),
                    ));
                } catch (\Throwable $e) {
                    $this->line('metric token: failed (' . $e::class . ': ' . $e->getMessage() . ')');
                    $failed = true;
                }
            }
        }

        // The log token: by its format only.
        $logsToken = $settings->logsToken();
        if ($logsToken !== null) {
            $configured++;
            if (! CredentialFormat::isLogsToken($logsToken)) {
                $this->line('log token: malformed (' . CredentialFormat::mask($logsToken) . '), set in WT15_LOGS_TOKEN');
                $malformed = true;
            } else {
                $this->line('log token: format valid; not checked remotely: a log push is a write, and log ingest has no read-only call');
            }
        }

        if ($configured === 0) {
            $this->line('no credential configured: set WT15_API_KEY, WT15_METRICS_TOKEN or WT15_LOGS_TOKEN');
        }

        // The configuration's own problems, whatever the credentials.
        foreach ($pipe->validated()->problems as $problem) {
            $this->line('Disabled: ' . $problem);
        }
        $store = $pipe->store();
        if (! $store->accepted()) {
            $this->line('Cache store refused: ' . $store->reason);
            $failed = true;
        }

        if ($configured === 0 || $malformed) {
            return 2;
        }

        return $failed ? 1 : 0;
    }

    private function skew(Client $client): string
    {
        $info = $client->lastResponse();
        if ($info === null) {
            return '';
        }
        foreach ($info->headers as $name => $values) {
            if (strcasecmp((string) $name, 'Date') === 0 && isset($values[0])) {
                $at = strtotime($values[0]);

                return $at === false ? '' : sprintf('; clock skew %d s', abs(time() - $at));
            }
        }

        return '';
    }
}
