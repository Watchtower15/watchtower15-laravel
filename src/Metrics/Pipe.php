<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Metrics;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use WatchTower15\Laravel\Config\ConfigValidator;
use WatchTower15\Laravel\Config\Settings;
use WatchTower15\Laravel\Config\ValidatedConfig;
use WatchTower15\Laravel\Support\UrlRule;

/**
 * What the metrics pipe may do, read from configuration alone: the valid rows, the cache store's driver rule (D5) and the URL
 * rule (D14). No store, token, Client or HTTP call is made here. Scoped.
 *
 * @internal
 */
final class Pipe
{
    private ?StoreDecision $store = null;

    private ?ValidatedConfig $validated = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Repository $config,
        private readonly Application $app,
    ) {
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function store(): StoreDecision
    {
        return $this->store ??= StoreRule::decide($this->config, $this->settings->bufferStore(), $this->app->environment('testing') === true);
    }

    public function validated(): ValidatedConfig
    {
        if ($this->validated === null) {
            $channels = $this->config->get('logging.channels');
            $this->validated = ConfigValidator::validate(
                $this->settings,
                $this->store()->driver,
                is_array($channels) ? array_map('strval', array_keys($channels)) : [],
            );
        }

        return $this->validated;
    }

    /** @return ?string why `ingest_url` is not usable, or null */
    public function ingestProblem(): ?string
    {
        return UrlRule::problem($this->settings->ingestUrl(), 'ingestUrl');
    }

    /** @return ?string why `api_url` is not usable, or null */
    public function apiProblem(): ?string
    {
        return UrlRule::problem($this->settings->apiUrl(), 'apiUrl');
    }

    /** True when the application meant to count something: a valid row or a heartbeat. */
    public function hasMetrics(): bool
    {
        return ! $this->validated()->isEmpty();
    }

    /**
     * Why nothing can be sent, or null: the store rule first, then the URL rule.
     */
    public function refusal(): ?string
    {
        $store = $this->store();
        if (! $store->accepted()) {
            return $store->reason;
        }
        $url = $this->ingestProblem();

        return $url === null ? null : UrlRule::variable('ingestUrl') . ' is invalid: ' . $url;
    }
}
