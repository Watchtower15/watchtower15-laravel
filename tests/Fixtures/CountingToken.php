<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Fixtures;

use WatchTower15\Laravel\Tests\Support\Keys;

/** An invokable token that counts its calls and returns a valid metric token. */
final class CountingToken
{
    public static int $calls = 0;

    public static string $answer = '';

    public function __invoke(): string
    {
        self::$calls++;

        return self::$answer !== '' ? self::$answer : Keys::metrics('d');
    }
}
