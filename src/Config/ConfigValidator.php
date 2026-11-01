<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Config;

/**
 * Checks the `wt15` configuration without any I/O (D4). A malformed row is disabled and listed, never thrown at boot. The
 * answer for the same inputs is kept for the life of the process.
 *
 * @internal
 */
final class ConfigValidator
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    private const KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/';

    private const MEMO_LIMIT = 64;

    /** @var array<string, ValidatedConfig> */
    private static array $memo = [];

    /**
     * @param ?string      $storeDriver the buffer store's driver, from config alone; the `database` driver refuses `after_commit: false` rows
     * @param list<string> $channels    the keys of `logging.channels`
     */
    public static function validate(Settings $settings, ?string $storeDriver, array $channels): ValidatedConfig
    {
        $key = null;
        try {
            $key = md5(serialize([$settings->toArray(), $storeDriver, $channels]));
        } catch (\Throwable) {
            // A configuration that cannot be serialised is checked every time.
        }
        if ($key !== null && isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $validated = self::check($settings, $storeDriver, $channels);
        if ($key !== null) {
            if (count(self::$memo) >= self::MEMO_LIMIT) {
                self::$memo = [];
            }
            self::$memo[$key] = $validated;
        }

        return $validated;
    }

    /** @internal for tests: forgets every answer. */
    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * @param list<string> $channels
     */
    private static function check(Settings $settings, ?string $storeDriver, array $channels): ValidatedConfig
    {
        $problems = [];
        $rows = [];
        foreach ($settings->metrics() as $metric => $definition) {
            $metric = (string) $metric;
            $problem = null;
            $row = self::row($metric, $definition, $settings->afterCommit(), $storeDriver, $problem);
            if ($row !== null) {
                $rows[$metric] = $row;
            } elseif ($problem !== null) {
                $problems[] = $metric . ': ' . $problem;
            }
        }

        $heartbeat = $settings->heartbeat();
        if ($heartbeat !== null && ! self::validKey($heartbeat)) {
            $problems[] = 'flush.heartbeat: not a valid metric key';
            $heartbeat = null;
        }

        $level = $settings->logLevel();
        $logLevel = 'warning';
        if (is_string($level) && in_array(strtolower($level), self::LEVELS, true)) {
            $logLevel = strtolower($level);
        } else {
            $problems[] = 'log.level: not a PSR-3 level';
        }

        $channel = $settings->logChannel();
        $logChannel = null;
        if (is_string($channel) && in_array($channel, $channels, true)) {
            $logChannel = $channel;
        } elseif ($channel !== null) {
            $problems[] = 'log.channel: not a configured channel';
        }

        return new ValidatedConfig($rows, $heartbeat, $logLevel, $logChannel, $problems);
    }

    private static function row(string $metric, mixed $definition, bool $afterCommitDefault, ?string $storeDriver, ?string &$problem): ?MetricDefinition
    {
        if (! self::validKey($metric)) {
            $problem = 'not a valid metric key';

            return null;
        }
        if (! is_array($definition)) {
            $problem = 'the row is not an array';

            return null;
        }
        $type = $definition['type'] ?? null;
        if ($type !== 'count' && $type !== 'level') {
            $problem = 'type must be count or level';

            return null;
        }
        $aggregate = $definition['aggregate'] ?? 'last';
        if ($aggregate !== 'last') {
            $problem = 'aggregate: only last is supported';

            return null;
        }
        $on = self::events($definition['on'] ?? null, $problem);
        if ($on === null) {
            return null;
        }
        $when = null;
        $value = null;
        try {
            if (($definition['when'] ?? null) !== null) {
                $when = Expression::parse($definition['when']);
            }
        } catch (\InvalidArgumentException $e) {
            $problem = 'when: ' . $e->getMessage();

            return null;
        }
        try {
            if (($definition['value'] ?? null) !== null) {
                $value = Expression::parse($definition['value']);
            }
        } catch (\InvalidArgumentException $e) {
            $problem = 'value: ' . $e->getMessage();

            return null;
        }
        if ($type === 'level' && $on !== [] && $value === null) {
            $problem = 'value: a level binding needs a value';

            return null;
        }
        $afterCommit = $definition['after_commit'] ?? null;
        if ($afterCommit !== null && ! is_bool($afterCommit)) {
            $problem = 'after_commit: must be true or false';

            return null;
        }
        if (($afterCommit ?? $afterCommitDefault) === false && $storeDriver === 'database') {
            $problem = 'after_commit: false is not allowed on the database cache store';

            return null;
        }

        return new MetricDefinition($metric, $type, $on, $when, $value, $afterCommit);
    }

    /**
     * @return list<string>|null null when the value is malformed (the reason in $problem)
     */
    private static function events(mixed $on, ?string &$problem): ?array
    {
        if ($on === null) {
            return [];
        }
        $names = is_array($on) ? array_values($on) : [$on];
        $clean = [];
        foreach ($names as $name) {
            if (! is_string($name) || trim($name) === '' || preg_match('/[\x00-\x1f]/', $name) === 1) {
                $problem = 'on: must be an event class, an eloquent.<event>: <Model> string, or a list of them';

                return null;
            }
            $name = ltrim(trim($name), '\\');
            if (str_starts_with($name, 'eloquent.') && preg_match('/\Aeloquent\.[a-z*]+: \S+\z/', $name) !== 1) {
                $problem = 'on: an eloquent binding reads eloquent.<event>: <Model>';

                return null;
            }
            $clean[$name] = true;
        }

        return array_map('strval', array_keys($clean));
    }

    private static function validKey(string $key): bool
    {
        return strlen($key) <= 100 && preg_match(self::KEY_PATTERN, $key) === 1;
    }
}
