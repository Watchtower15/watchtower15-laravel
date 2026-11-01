<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use WatchTower15\Laravel\Tests\TestCase;

/** The scheduler's entry, LB13. */
abstract class SchedulesFlush extends TestCase
{
    /** @return list<Event> */
    protected function flushEvents(): array
    {
        return array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn (Event $event): bool => str_contains($event->command ?? '', 'wt15:flush'),
        ));
    }
}
