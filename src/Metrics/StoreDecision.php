<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Metrics;

use WatchTower15\Metrics\StoreScope;

/**
 * Whether the configured cache store may hold the buckets (D5), read from configuration alone.
 *
 * @internal
 */
final readonly class StoreDecision
{
    public function __construct(
        public string $store,
        public ?string $driver,
        public ?string $reason,
        public ?StoreScope $scope,
    ) {
    }

    public function accepted(): bool
    {
        return $this->reason === null;
    }
}
