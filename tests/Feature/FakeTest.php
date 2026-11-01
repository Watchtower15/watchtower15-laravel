<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Tests\Fixtures\OrderRefunded;
use WatchTower15\Laravel\Tests\Fixtures\Widget;
use WatchTower15\Laravel\Tests\Support\Keys;
use WatchTower15\Laravel\Tests\TestCase;
use WatchTower15\Testing\MockTransport;

/** LB24: `Wt15::fake()`. */
final class FakeTest extends TestCase
{
    protected function wt15Config(): array
    {
        return [
            'enabled' => false,
            'api_key' => Keys::apiKey(),
            'token' => Keys::metrics(),
            'metrics' => [
                'widgets.created' => ['type' => 'count', 'on' => 'eloquent.created: ' . Widget::class],
                'orders.refunded' => ['type' => 'count', 'on' => OrderRefunded::class],
                'queue.depth' => ['type' => 'level'],
            ],
        ];
    }

    public function testTheFakeRecordsWhatProductionWouldCountAndSendsNothing(): void
    {
        Http::fake();
        $fake = Wt15::fake();

        DB::transaction(static fn (): Widget => Widget::create(['amount' => 1]));
        try {
            DB::transaction(static function (): void {
                Widget::create(['amount' => 1]);

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
            // Expected.
        }
        event(new OrderRefunded());
        Wt15::gauge('queue.depth', 7);
        Wt15::increment('typo.key');
        Wt15::gauge('orders.refunded', 3);

        $fake->assertIncremented('widgets.created', 1);
        $fake->assertIncremented('orders.refunded', 1);
        $fake->assertGaugeSet('queue.depth', 7);
        $fake->assertNotIncremented('typo.key');
        try {
            $fake->assertGaugeSet('orders.refunded');
            self::fail('a gauge on a count row is not recorded');
        } catch (AssertionFailedError) {
            // Expected: the fake applies the allow-list.
        }

        Wt15::client()->queue('public.me.view', MockTransport::envelope(200, [
            'key' => ['uuid' => 'k', 'name' => 'ci key', 'last_four' => 'aaaa', 'scopes' => [], 'created_at' => '2026-10-01T00:00:00Z', 'expires_at' => null],
            'user' => ['uuid' => 'u', 'name' => 'Ada', 'email' => 'ada@example.com'],
            'organisation' => ['uuid' => 'o', 'name' => 'Acme'],
        ]));
        self::assertSame('ci key', Wt15::me()->keyName);
        Wt15::client()->assertCalled('public.me.view');

        self::assertSame(0, Artisan::call('wt15:flush'), 'no scope refusal under the fake');
        Wt15::client()->assertPushed(static fn (array $point): bool => ($point['metric'] ?? null) === 'widgets.created');
        Http::assertNothingSent();

        try {
            $fake->assertNothingRecorded();
            self::fail('something was recorded');
        } catch (AssertionFailedError) {
            // Expected.
        }
    }

    public function testTheFakeTurnsTheBridgeOnForTheTest(): void
    {
        self::assertFalse($this->app['config']->get('wt15.enabled'));

        $fake = Wt15::fake();
        Wt15::increment('orders.refunded');

        $fake->assertIncremented('orders.refunded');
    }
}
