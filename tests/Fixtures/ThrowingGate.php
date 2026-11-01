<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Fixtures;

/** An invokable that throws. */
final class ThrowingGate
{
    public function __invoke(mixed $subject): bool
    {
        throw new \RuntimeException('the gate failed');
    }
}
