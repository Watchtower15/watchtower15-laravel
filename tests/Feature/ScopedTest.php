<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use WatchTower15\Client;
use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Tests\Fixtures\OrderRefunded;
use WatchTower15\Laravel\Tests\Support\Keys;
use WatchTower15\Laravel\Tests\Support\Wire;
use WatchTower15\Laravel\Wt15Manager;
use WatchTower15\Metrics\Aggregator;

/** LB23 (the scoped bindings) and LB27 (the User-Agent). */
final class ScopedTest extends PipeTestCase
{
    protected function wt15Config(): array
    {
        return [
            'fake_in_tests' => false,
            'api_key' => Keys::apiKey(),
            'token' => Keys::metrics(),
            'metrics' => ['orders.refunded' => ['type' => 'count', 'on' => OrderRefunded::class]],
        ];
    }

    public function testScopedInstancesDieWithTheRequestAndNothingIsCarriedOver(): void
    {
        $manager = $this->app->make(Wt15Manager::class);
        $client = $this->app->make(Client::class);
        $aggregator = $this->app->make(Aggregator::class);

        // A fake set after boot answers the next send.
        Wire::standard();
        self::assertSame('ci key', Wt15::me()->keyName);
        self::assertNotNull($client->lastResponse());

        $this->app->forgetScopedInstances();

        self::assertNotSame($manager, $this->app->make(Wt15Manager::class));
        self::assertNotSame($aggregator, $this->app->make(Aggregator::class));
        $fresh = $this->app->make(Client::class);
        self::assertNotSame($client, $fresh);
        self::assertNull($fresh->lastResponse());
    }

    public function testEachListenerRunsOncePerEvent(): void
    {
        event(new OrderRefunded());
        event(new OrderRefunded());

        self::assertSame(2, $this->counted('orders.refunded'));
    }

    public function testTheUserAgentNamesLaravelAfterPhp(): void
    {
        Wire::standard();
        Wt15::me();
        Wt15::increment('orders.refunded');
        $this->travel(65)->seconds();
        $this->artisan('wt15:flush')->assertExitCode(0);

        $agents = [];
        foreach (Http::recorded() as [$request]) {
            self::assertInstanceOf(Request::class, $request);
            $agents[] = $request->header('User-Agent')[0] ?? '';
        }
        self::assertCount(2, $agents, 'the me() request and the flush');
        foreach ($agents as $agent) {
            self::assertMatchesRegularExpression('~ php/\d+\.\d+ laravel/' . preg_quote($this->app->version(), '~') . '~', $agent);
        }
    }
}
