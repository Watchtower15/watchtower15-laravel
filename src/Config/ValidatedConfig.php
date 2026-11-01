<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Config;

/**
 * What the validator made of the configuration: the valid rows, the heartbeat it accepted, the `log` settings in force and
 * every problem, each as `<row key, flush.heartbeat, log.level or log.channel>: <reason>` and never a value.
 *
 * @internal
 */
final readonly class ValidatedConfig
{
    /**
     * @param array<string, MetricDefinition> $rows
     * @param list<string>                    $problems
     */
    public function __construct(
        public array $rows,
        public ?string $heartbeat,
        public string $logLevel,
        public ?string $logChannel,
        public array $problems,
    ) {
    }

    /** True when there is nothing to count and nothing to send: no valid row and no heartbeat. */
    public function isEmpty(): bool
    {
        return $this->rows === [] && $this->heartbeat === null;
    }

    /**
     * The core's allow-list: the valid rows and their types.
     *
     * @return array<string, 'count'|'level'>
     */
    public function allowList(): array
    {
        $allow = [];
        foreach ($this->rows as $key => $row) {
            $allow[$key] = $row->type;
        }

        return $allow;
    }

    /**
     * Every event name some row listens to, once each, in the order the rows name them.
     *
     * @return list<string>
     */
    public function eventNames(): array
    {
        $names = [];
        foreach ($this->rows as $row) {
            foreach ($row->on as $name) {
                $names[$name] = true;
            }
        }

        return array_map('strval', array_keys($names));
    }
}
