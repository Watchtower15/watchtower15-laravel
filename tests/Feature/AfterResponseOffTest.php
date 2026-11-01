<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Tests\Support\Wire;

/** LB25: with `flush.after_response` false no terminating callback is registered, even outside the console. */
final class AfterResponseOffTest extends PipeTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineMore($app): void
    {
        parent::defineMore($app);
        (new \ReflectionProperty($app, 'isRunningInConsole'))->setValue($app, false);
    }

    public function testTerminateDoesNothing(): void
    {
        Wire::standard();
        Wt15::increment('orders.shipped');
        $this->closeBucket();

        $this->app->terminate();

        self::assertSame([], $this->sent());
    }
}
