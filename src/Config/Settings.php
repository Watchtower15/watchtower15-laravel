<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Config;

/**
 * The merged `wt15` configuration (D2): the application's values over the defaults, `buffer`, `flush` and `log` merged key by
 * key, `metrics` taken as written, unknown keys ignored. Scoped: it is read once per request or job.
 */
final class Settings
{
    public const DEFAULTS = [
        'enabled' => true,
        'api_key' => null,
        'token' => '',
        'logs_token' => null,
        'api_url' => null,
        'ingest_url' => null,
        'after_commit' => true,
        'fake_in_tests' => true,
        'buffer' => ['store' => null, 'prefix' => 'wt15'],
        'flush' => ['schedule' => true, 'after_response' => false, 'heartbeat' => null, 'max_buckets' => 60, 'max_requests' => 10, 'timeout' => 3],
        'log' => ['channel' => null, 'level' => 'warning'],
        'metrics' => [],
    ];

    /** @var array<string, mixed> */
    private readonly array $values;

    /**
     * @param array<array-key, mixed> $raw the application's `wt15` array
     */
    public function __construct(array $raw)
    {
        $values = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $given = $raw[$key];
            if (in_array($key, ['buffer', 'flush', 'log'], true)) {
                if (is_array($given) && is_array($default)) {
                    $values[$key] = array_replace_recursive($default, $given);
                }

                continue;
            }
            if ($key === 'metrics') {
                $values[$key] = is_array($given) ? $given : [];

                continue;
            }
            $values[$key] = $given;
        }
        $this->values = $values;
    }

    public function enabled(): bool
    {
        return filter_var($this->values['enabled'], FILTER_VALIDATE_BOOLEAN);
    }

    public function afterCommit(): bool
    {
        return filter_var($this->values['after_commit'], FILTER_VALIDATE_BOOLEAN);
    }

    public function fakeInTests(): bool
    {
        return filter_var($this->values['fake_in_tests'], FILTER_VALIDATE_BOOLEAN);
    }

    public function apiKey(): ?string
    {
        return self::text($this->values['api_key']);
    }

    /** The metric token as configured: a string, or the name of an invokable class (D3). */
    public function token(): string
    {
        return is_string($this->values['token']) ? $this->values['token'] : '';
    }

    public function logsToken(): ?string
    {
        return self::text($this->values['logs_token']);
    }

    public function apiUrl(): ?string
    {
        return self::text($this->values['api_url']);
    }

    public function ingestUrl(): ?string
    {
        return self::text($this->values['ingest_url']);
    }

    /** The cache store name that holds the buckets; null is the application's default store. */
    public function bufferStore(): ?string
    {
        return self::text($this->section('buffer')['store'] ?? null);
    }

    public function bufferPrefix(): string
    {
        $prefix = self::text($this->section('buffer')['prefix'] ?? null);

        return $prefix ?? 'wt15';
    }

    public function schedule(): bool
    {
        return filter_var($this->section('flush')['schedule'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    public function afterResponse(): bool
    {
        return filter_var($this->section('flush')['after_response'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function heartbeat(): ?string
    {
        $heartbeat = $this->section('flush')['heartbeat'] ?? null;

        return is_string($heartbeat) && $heartbeat !== '' ? $heartbeat : null;
    }

    public function maxBuckets(): int
    {
        return self::number($this->section('flush')['max_buckets'] ?? null, 60);
    }

    public function maxRequests(): int
    {
        return self::number($this->section('flush')['max_requests'] ?? null, 10);
    }

    public function timeout(): float
    {
        $timeout = $this->section('flush')['timeout'] ?? null;

        return is_numeric($timeout) ? (float) $timeout : 3.0;
    }

    public function logChannel(): mixed
    {
        return $this->section('log')['channel'] ?? null;
    }

    public function logLevel(): mixed
    {
        return $this->section('log')['level'] ?? 'warning';
    }

    /**
     * @return array<array-key, mixed>
     */
    public function metrics(): array
    {
        /** @var array<array-key, mixed> $metrics */
        $metrics = $this->values['metrics'];

        return $metrics;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function section(string $name): array
    {
        $section = $this->values[$name] ?? [];

        return is_array($section) ? $section : [];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function number(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
