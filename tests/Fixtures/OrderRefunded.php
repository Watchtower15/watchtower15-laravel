<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Fixtures;

final class OrderRefunded
{
    public function __construct(public int $amount = 1, public string $region = 'eu', public ?string $note = null, public bool $flag = false)
    {
    }
}
