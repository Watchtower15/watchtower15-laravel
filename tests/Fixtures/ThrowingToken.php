<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Fixtures;

use WatchTower15\Laravel\Tests\Support\Keys;

/** An invokable token that throws. */
final class ThrowingToken
{
    public function __invoke(): string
    {
        throw new \RuntimeException('the secrets store is down');
    }
}
