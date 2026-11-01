<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use WatchTower15\Laravel\Config\Settings;
use WatchTower15\Laravel\Tests\Support\Keys;
use WatchTower15\Laravel\Tests\TestCase;

/** With no published `config/wt15.php`, the environment variables the package names are still read. */
final class UnpublishedConfigTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['WT15_LOGS_TOKEN'] = Keys::logs();
        $_ENV['WT15_METRICS_TOKEN'] = Keys::metrics();
        $_SERVER['WT15_LOGS_TOKEN'] = Keys::logs();
        $_SERVER['WT15_METRICS_TOKEN'] = Keys::metrics();
        putenv('WT15_LOGS_TOKEN=' . Keys::logs());
        putenv('WT15_METRICS_TOKEN=' . Keys::metrics());
        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_ENV['WT15_LOGS_TOKEN'], $_ENV['WT15_METRICS_TOKEN'], $_SERVER['WT15_LOGS_TOKEN'], $_SERVER['WT15_METRICS_TOKEN']);
        putenv('WT15_LOGS_TOKEN');
        putenv('WT15_METRICS_TOKEN');
        parent::tearDown();
    }

    public function testTheCredentialsComeFromTheEnvironment(): void
    {
        $settings = $this->app->make(Settings::class);

        self::assertSame(Keys::logs(), $settings->logsToken());
        self::assertSame(Keys::metrics(), $settings->token());
        self::assertSame(3.0, $settings->timeout(), 'the nested defaults are still merged key by key');
    }
}
