<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Tests\Support\Wire;

/** LB25: an artisan command's terminate (the console) never flushes. */
final class ConsoleTerminateTest extends PipeTestCase
{
    protected function wt15Config(): array
    {
        $config = parent::wt15Config();
        $config['flush'] = ['after_response' => true];

        return $config;
    }

    public function testUnderTheConsoleTheCallbackReturnsAtOnce(): void
    {
        Wire::standard();
        Wt15::increment('orders.shipped');
        $this->closeBucket();
        $before = $this->sent();

        $this->app->terminate();

        self::assertSame($before, $this->sent());
        self::assertSame([], $this->posts());
    }
}
