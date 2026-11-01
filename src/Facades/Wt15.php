<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Facades;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use WatchTower15\Client;
use WatchTower15\Laravel\Testing\Wt15Fake;
use WatchTower15\Laravel\Wt15Manager;

/**
 * @method static Client client()
 * @method static void increment(string $key, int $by = 1)
 * @method static void gauge(string $key, int|float $value)
 * @method static \WatchTower15\Api\Me me(\WatchTower15\Http\RequestOptions|array<string, int|float>|null $options = null)
 * @method static \WatchTower15\Http\ResponseInfo|null lastResponse()
 *
 * @see Wt15Manager
 */
final class Wt15 extends Facade
{
    /** The manager is scoped: a facade that kept it would serve one request's instance to the next under Octane (D9). */
    protected static $cached = false;

    /**
     * Swaps the manager for a fake that records counters, gauges and API calls and never sends (D11).
     */
    public static function fake(): Wt15Fake
    {
        $fake = new Wt15Fake();
        $fake->arm();
        // A singleton, not an instance: a later forgetScopedInstances() would drop an instance and the listeners would build a real manager.
        Container::getInstance()->singleton(Wt15Manager::class, static fn (): Wt15Fake => $fake);
        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Wt15Manager::class;
    }
}
