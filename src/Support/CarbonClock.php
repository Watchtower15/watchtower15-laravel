<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Support;

use Illuminate\Support\Carbon;
use WatchTower15\Internal\Clock;

/**
 * The core's clock on Carbon, so a test's `travel()` moves the minute buckets.
 *
 * @internal
 */
final class CarbonClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface(Carbon::now('UTC'));
    }
}
