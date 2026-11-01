<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Log\Events\MessageLogged;
use WatchTower15\Client;
use WatchTower15\Laravel\Metrics\CacheBucketStore;
use WatchTower15\Laravel\Tests\Fixtures\CountingToken;
use WatchTower15\Laravel\Tests\Fixtures\OrderRefunded;
use WatchTower15\Laravel\Tests\Fixtures\Widget;
use WatchTower15\Laravel\Tests\TestCase;
use WatchTower15\Metrics\Aggregator;

/** LB15: booting with a full configuration does no I/O and resolves nothing. */
final class BootTest extends TestCase
{
    /** @var list<string> */
    private static array $resolved = [];

    /** @var list<string> */
    private static array $touched = [];

    protected function wt15Config(): array
    {
        return [
            'api_key' => 'wt15ak_truncated',
            'token' => CountingToken::class,
            'fake_in_tests' => false,
            'flush' => ['after_response' => true, 'heartbeat' => 'wt15.flush'],
            'metrics' => [
                'orders.refunded' => ['type' => 'count', 'on' => OrderRefunded::class],
                'widgets.created' => ['type' => 'count', 'on' => 'eloquent.created: ' . Widget::class],
            ],
        ];
    }

    protected function defineMore($app): void
    {
        self::$resolved = [];
        self::$touched = [];
        $events = $app['events'];
        $events->listen('Illuminate\Cache\Events\*', static function (string $name): void {
            self::$touched[] = $name;
        });
        $events->listen(RequestSending::class, static function (): void {
            self::$touched[] = 'http';
        });
        $events->listen(MessageLogged::class, static function (): void {
            self::$touched[] = 'log';
        });
        $app->resolving(static function (mixed $object): void {
            self::$resolved[] = is_object($object) ? $object::class : 'string';
        });
    }

    public function testBootResolvesNothingAndDoesNoIo(): void
    {
        self::assertSame([], self::$touched, 'no cache, HTTP or log activity at boot');
        self::assertSame(0, CountingToken::$calls, 'no token resolution');
        foreach ([Client::class, Aggregator::class, CacheBucketStore::class, \WatchTower15\Testing\FakeClient::class] as $class) {
            self::assertNotContains($class, self::$resolved, $class . ' is not resolved at boot');
        }
    }

    public function testOnlyTheConfiguredNamesAreListenedTo(): void
    {
        $events = $this->app['events'];

        self::assertTrue($events->hasListeners(OrderRefunded::class));
        self::assertTrue($events->hasListeners('eloquent.created: ' . Widget::class));
        self::assertFalse($events->hasListeners('some.other.event'));
        self::assertFalse($events->hasListeners('*'), 'no catch-all listener');
        self::assertFalse($events->hasListeners('eloquent.retrieved: ' . Widget::class));
    }
}
