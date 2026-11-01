<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Fixtures;

/** An invokable `when`: true when the event's region equals the constructor argument. */
final class RegionGate
{
    public function __construct(private readonly string $region = 'eu')
    {
    }

    public function __invoke(OrderRefunded $event): bool
    {
        return $event->region === $this->region;
    }
}
