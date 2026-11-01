<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Fixtures;

use Illuminate\Cache\ArrayStore;

/** An array-backed cache store with an `add()` of its own that records every write. */
class SpyCacheStore extends ArrayStore
{
    /** @var list<array{string, string, int}> */
    public array $writes = [];

    /** @var list<string> */
    public array $reads = [];

    public bool $failGets = false;

    public bool $manyUsed = false;

    public function add($key, $value, $seconds)
    {
        $this->writes[] = ['add', (string) $key, (int) $seconds];

        if ($this->get($key) !== null) {
            return false;
        }

        return parent::put($key, $value, $seconds);
    }

    public function put($key, $value, $seconds)
    {
        $this->writes[] = ['put', (string) $key, (int) $seconds];

        return parent::put($key, $value, $seconds);
    }

    public function get($key)
    {
        $this->reads[] = (string) $key;
        if ($this->failGets) {
            throw new \RuntimeException('a throttled read');
        }

        return parent::get($key);
    }

    public function many(array $keys)
    {
        $this->manyUsed = true;

        return parent::many($keys);
    }
}
