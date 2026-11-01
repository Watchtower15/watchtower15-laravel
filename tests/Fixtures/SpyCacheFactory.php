<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Fixtures;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;

/** A cache factory that hands out one repository over the given store. */
final class SpyCacheFactory implements Factory
{
    public function __construct(private readonly SpyCacheStore $store)
    {
    }

    public function store($name = null)
    {
        return new Repository($this->store);
    }
}
