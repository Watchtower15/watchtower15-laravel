<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Metrics;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use WatchTower15\Metrics\BucketStore;
use WatchTower15\Metrics\StoreScope;

/**
 * The core's `BucketStore` on a Laravel cache store (D5): a count by add-then-increment, a level by put, reads by `many()` over
 * the allow-list (a cache store cannot list its keys), one key at a time on DynamoDB, whose `many()` is a capped batch.
 *
 * Every call is made on the cache store itself, never through the `Repository`, so no cache event is dispatched and the
 * bridge's keys never reach Pulse or Telescope; only the `array` store, which has no `add()`, adds through the `Repository`.
 * The repository is resolved when the store is built, so a store that cannot be built throws there, in the caller's own catch.
 *
 * @internal
 */
final class CacheBucketStore implements BucketStore
{
    /** The reasons whose counters the store reads: the core's (SDK03 D15) and the bridge's own `binding` (D4). */
    public const ERROR_REASONS = [
        'not_allowed', 'invalid_key', 'invalid_value', 'negative_count', 'late_write', 'auth', 'config',
        'dropped_age', 'dropped_rejected', 'internal', 'redirect', 'transport', 'type_conflict', 'binding',
    ];

    private const READ_CHUNK = 1000;

    private const ERROR_TTL = 2_592_000;

    private const TEN_YEARS = 315_360_000;

    private readonly Repository $repository;

    private readonly Store $store;

    /**
     * @param array<string, 'count'|'level'> $allowList the valid rows and the heartbeat: the keys a bucket can hold
     */
    public function __construct(
        Factory $cache,
        ?string $storeName,
        private readonly string $prefix,
        private readonly StoreScope $scope,
        private readonly string $driver,
        private readonly array $allowList,
    ) {
        $repository = $cache->store($storeName);
        if (! $repository instanceof \Illuminate\Cache\Repository) {
            throw new \RuntimeException('The cache store did not give a repository.');
        }
        $this->repository = $repository;
        $this->store = $repository->getStore();
    }

    public function scope(): StoreScope
    {
        return $this->scope;
    }

    public function increment(int $bucket, string $key, int $by, int $ttl): void
    {
        $this->bump($this->bucketKey($bucket, 'c', $key), $by, $ttl);
    }

    public function setLevel(int $bucket, string $key, int|float $value, int $ttl): void
    {
        if (! $this->store->put($this->bucketKey($bucket, 'l', $key), $value, $ttl)) {
            throw new \RuntimeException('The cache store did not write the level.');
        }
    }

    public function read(int $bucket): array
    {
        $count = [];
        $level = [];
        foreach ($this->read1($bucket, 'count', 'c') as $key => $value) {
            $number = is_numeric($value) ? (int) $value : 0;
            if ($number > 0) {
                $count[$key] = $number;
            }
        }
        foreach ($this->read1($bucket, 'level', 'l') as $key => $value) {
            $level[$key] = is_numeric($value) ? (float) $value : 0.0;
        }

        return ['count' => $count, 'level' => $level];
    }

    public function forget(int $bucket): void
    {
        foreach ($this->allowList as $key => $type) {
            $this->store->forget($this->bucketKey($bucket, $type === 'count' ? 'c' : 'l', $key));
        }
    }

    public function lastFlushed(): ?int
    {
        $last = $this->store->get($this->prefix . ':last');

        return $last === null ? null : (int) (is_scalar($last) ? $last : 0);
    }

    public function markFlushed(int $bucket): void
    {
        if (! $this->store->forever($this->prefix . ':last', $bucket)) {
            throw new \RuntimeException('The cache store did not write the last flushed bucket.');
        }
    }

    public function acquireLock(string $name, int $ttl): ?string
    {
        $lock = $this->locks()->lock($this->prefix . ':lock:' . $name, $ttl);

        return $lock->get() ? $lock->owner() : null;
    }

    public function releaseLock(string $name, string $token): void
    {
        $this->locks()->restoreLock($this->prefix . ':lock:' . $name, $token)->release();
    }

    public function claimOnce(string $name, int $ttl): bool
    {
        return $this->add($this->prefix . ':claim:' . $name, 1, $ttl);
    }

    public function addError(string $reason, int $by = 1): void
    {
        $this->bump($this->prefix . ':errors:' . $reason, $by, self::ERROR_TTL);
    }

    public function errors(): array
    {
        $names = [];
        foreach (self::ERROR_REASONS as $reason) {
            $names[$this->prefix . ':errors:' . $reason] = $reason;
        }
        $errors = [];
        foreach ($this->fetch(array_keys($names)) as $name => $value) {
            $number = is_numeric($value) ? (int) $value : 0;
            if ($number > 0 && isset($names[$name])) {
                $errors[$names[$name]] = $number;
            }
        }

        return $errors;
    }

    public function subtractErrors(array $counts): void
    {
        foreach ($counts as $reason => $count) {
            if ($count > 0) {
                $this->store->decrement($this->prefix . ':errors:' . $reason, $count);
            }
        }
    }

    public function instanceId(): string
    {
        $name = $this->prefix . ':instance';
        $this->add($name, bin2hex(random_bytes(16)), self::TEN_YEARS);
        $id = $this->store->get($name);
        if (! is_string($id) || preg_match('/\A[0-9a-f]{32}\z/', $id) !== 1) {
            throw new \RuntimeException('The cache store did not keep the instance id.');
        }

        return $id;
    }

    /**
     * Add the key at 0, then increment it; once more when the key expired between the two.
     */
    private function bump(string $name, int $by, int $ttl): void
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->add($name, 0, $ttl);
            if ($this->store->increment($name, $by) !== false) {
                return;
            }
        }

        throw new \RuntimeException('The cache store did not increment the key.');
    }

    /**
     * Add-if-absent with a TTL, on the store itself; only a store with no `add()` goes through the repository.
     */
    private function add(string $name, mixed $value, int $seconds): bool
    {
        if (method_exists($this->store, 'add')) {
            return (bool) $this->store->add($name, $value, $seconds);
        }

        return $this->repository->add($name, $value, $seconds);
    }

    private function locks(): LockProvider
    {
        if (! $this->store instanceof LockProvider) {
            throw new \RuntimeException('The cache store gives no locks.');
        }

        return $this->store;
    }

    /**
     * @param 'count'|'level' $type
     *
     * @return array<string, mixed> key => stored value, for the keys that hold one
     */
    private function read1(int $bucket, string $type, string $kind): array
    {
        $names = [];
        foreach ($this->allowList as $key => $keyType) {
            if ($keyType === $type) {
                $names[$this->bucketKey($bucket, $kind, $key)] = $key;
            }
        }
        $found = [];
        foreach ($this->fetch(array_keys($names)) as $name => $value) {
            if ($value !== null && isset($names[$name])) {
                $found[$names[$name]] = $value;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, mixed>
     */
    private function fetch(array $names): array
    {
        $values = [];
        if ($this->driver === 'dynamodb') {
            foreach ($names as $name) {
                $values[$name] = $this->store->get($name);
            }

            return $values;
        }
        foreach (array_chunk($names, self::READ_CHUNK) as $chunk) {
            foreach ($this->store->many($chunk) as $name => $value) {
                $values[(string) $name] = $value;
            }
        }

        return $values;
    }

    private function bucketKey(int $bucket, string $kind, string $key): string
    {
        return $this->prefix . ':b:60:' . $bucket . ':' . $kind . ':' . $key;
    }
}
