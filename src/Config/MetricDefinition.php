<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Config;

/**
 * One valid row of `wt15.metrics` (D2, D4).
 *
 * @internal
 */
final readonly class MetricDefinition
{
    /**
     * @param 'count'|'level' $type
     * @param list<string>    $on    event class names, `eloquent.<event>: <Model>` strings or patterns; empty for a manual-only row
     */
    public function __construct(
        public string $key,
        public string $type,
        public array $on,
        public ?Expression $when,
        public ?Expression $value,
        public ?bool $afterCommit,
    ) {
    }
}
