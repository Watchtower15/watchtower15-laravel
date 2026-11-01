<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use WatchTower15\Laravel\Config\Settings;
use WatchTower15\Laravel\Tests\TestCase;

/** LB12: `config:cache` with the published config. */
final class ConfigCacheTest extends TestCase
{
    public function testThePublishedConfigIsCacheable(): void
    {
        $published = require __DIR__ . '/../../config/wt15.php';
        self::assertIsArray($published);
        $this->app['config']->set('wt15', $published);

        $exit = Artisan::call('config:cache');
        $output = Artisan::output();
        Artisan::call('config:clear');

        self::assertSame(0, $exit, $output);
    }

    public function testAPartialConfigKeepsTheDefaultsOfTheRest(): void
    {
        $settings = new Settings(['flush' => ['schedule' => true, 'heartbeat' => 'wt15.flush'], 'metrics' => ['a' => ['type' => 'count']]]);

        self::assertSame(3.0, $settings->timeout());
        self::assertSame(60, $settings->maxBuckets());
        self::assertSame(10, $settings->maxRequests());
        self::assertSame(['a' => ['type' => 'count']], $settings->metrics(), 'metrics are taken as written');
        self::assertSame('wt15', $settings->bufferPrefix());
    }
}
