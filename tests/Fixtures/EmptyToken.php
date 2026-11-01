<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Fixtures;

use WatchTower15\Laravel\Tests\Support\Keys;

/** An invokable token that returns an empty string. */
final class EmptyToken
{
    public function __invoke(): string
    {
        return '';
    }
}
