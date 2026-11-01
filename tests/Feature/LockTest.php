<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Tests\Support\SpiesOnTheStore;
use WatchTower15\Laravel\Tests\Support\Wire;

/** LB18: `wt15:flush` while the store's flush lock is held. */
final class LockTest extends PipeTestCase
{
    use SpiesOnTheStore;

    public function testAnotherFlushRunningIsReportedAndTheBridgeTakesNoLockOfItsOwn(): void
    {
        $this->app['config']->set('wt15.flush.after_response', false);
        Wire::standard();
        $spy = $this->spyStore();
        Wt15::increment('orders.shipped');
        $this->closeBucket();
        $token = $spy->inner->acquireLock('flush', 120);
        self::assertNotNull($token);

        self::assertSame(0, Artisan::call('wt15:flush'));
        self::assertStringContainsString('another flush is running', strtolower(Artisan::output()));
        self::assertSame([], $this->sent(), 'nothing sent');

        $spy->inner->releaseLock('flush', (string) $token);
        $spy->calls = [];
        self::assertSame(0, Artisan::call('wt15:flush'));
        self::assertCount(1, $this->posts(), 'each bucket once');
        self::assertSame(1, $spy->count('acquireLock'), 'the only lock is the flusher\'s');
    }
}
