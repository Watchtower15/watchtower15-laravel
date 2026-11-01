<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

/** LB13: with `flush.schedule` false the Schedule holds no such event. */
final class ScheduleOffTest extends SchedulesFlush
{
    protected function wt15Config(): array
    {
        return ['flush' => ['schedule' => false]];
    }

    public function testNoEventIsRegistered(): void
    {
        self::assertSame([], $this->flushEvents());
    }
}
