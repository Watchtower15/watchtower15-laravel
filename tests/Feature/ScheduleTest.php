<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

/** LB13: with `flush.schedule` true (the default) the scheduler's entry runs every minute, in the background, on one server. */
final class ScheduleTest extends SchedulesFlush
{
    public function testTheFlushRunsEveryMinuteInTheBackgroundOnOneServer(): void
    {
        $events = $this->flushEvents();

        self::assertCount(1, $events);
        self::assertStringContainsString('wt15:flush --scheduled', (string) $events[0]->command);
        self::assertSame('* * * * *', $events[0]->expression);
        self::assertSame(5, $events[0]->expiresAt);
        self::assertTrue($events[0]->onOneServer);
        self::assertTrue($events[0]->runInBackground);
        self::assertTrue($events[0]->withoutOverlapping);
    }
}
