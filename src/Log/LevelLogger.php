<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Log;

use Illuminate\Container\Container;
use Illuminate\Log\LogManager;
use Psr\Log\AbstractLogger;
use WatchTower15\Laravel\Metrics\Pipe;

/**
 * The SDK's PSR-3 logger (D19): every record is written to `log.channel` at `log.level`, whatever level the SDK chose, which
 * is kept in the record's context as `wt15_level`. A level or channel the validator refuses is never used: the record goes out at
 * `warning`, on the default channel. An instance made with problems carries the validator's problems in each record as
 * `wt15_config_problems` (the aggregator's alone, D6 c) and counts the records it writes.
 *
 * Scoped. It reaches the log manager at write time, so a `Log::swap()` or a fake applies.
 *
 * @internal
 */
final class LevelLogger extends AbstractLogger
{
    private int $written = 0;

    public function __construct(
        private readonly Pipe $pipe,
        private readonly bool $withProblems = false,
    ) {
    }

    /**
     * Untyped parameters: psr/log 1.x and 2.x declare `log()` without them, and a narrower type would not compile there.
     *
     * @param mixed                   $level
     * @param string|\Stringable      $message
     * @param array<array-key, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        try {
            $validated = $this->pipe->validated();
            $manager = Container::getInstance()->make('log');
            if (! $manager instanceof LogManager) {
                return;
            }
            $logger = $validated->logChannel === null ? $manager->channel() : $manager->channel($validated->logChannel);
            $context['wt15_level'] = is_string($level) ? $level : 'unknown';
            if ($this->withProblems && $validated->problems !== []) {
                $context['wt15_config_problems'] = $validated->problems;
            }
            $logger->log($validated->logLevel, (string) $message, $context);
            $this->written++;
        } catch (\Throwable) {
            // A log that cannot be written is ignored, as the core ignores a logger that throws.
        }
    }

    /** The records written so far (D6 c). */
    public function written(): int
    {
        return $this->written;
    }
}
