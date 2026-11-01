<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Tests\Support\Keys;
use WatchTower15\Laravel\Tests\Support\Wire;

/** LB9: under APP_ENV=testing with `fake_in_tests` left true the pipe sends into a fake client. */
final class FakeInTestsTest extends PipeTestCase
{
    protected function wt15Config(): array
    {
        $config = parent::wt15Config();
        $config['fake_in_tests'] = true;
        $config['api_key'] = Keys::apiKey();

        return $config;
    }

    public function testClosedBucketsAreForgottenAndNothingLeaves(): void
    {
        Wire::standard();
        $bucket = $this->bucket();
        Wt15::increment('orders.shipped');
        $this->closeBucket();

        self::assertSame(0, Artisan::call('wt15:flush'));

        Http::assertNothingSent();
        self::assertSame(0, $this->counted('orders.shipped', $bucket), 'the fake accepted it, so it is forgotten');

        // The facade's Client is not affected.
        self::assertSame('ci key', Wt15::me()->keyName);
        Http::assertSentCount(1);
    }
}
