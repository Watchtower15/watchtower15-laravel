<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Fixtures;

use Illuminate\Http\Request;

/** A middleware that runs a callback after the controller: the Vapor pattern of closing the channel before the response leaves. */
final class ClosesTheChannel
{
    public static ?\Closure $after = null;

    public function handle(Request $request, \Closure $next): mixed
    {
        $response = $next($request);
        if (self::$after !== null) {
            (self::$after)();
        }

        return $response;
    }
}
