<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Logs;

/**
 * The `watchtower15` channels' handlers that hold lines right now (D10). A handler adds itself when its buffer holds a record and
 * leaves when it flushes, so a worker that builds a channel per request or job keeps none past its flush. One instance, bound in
 * the provider's `register()`, shared by every clone of the application Octane makes; a plain object that touches no file,
 * cache store or network.
 *
 * @internal
 */
final class BufferedChannels
{
    /** @var \SplObjectStorage<TrackedBufferHandler, null> */
    private \SplObjectStorage $handlers;

    public function __construct()
    {
        $this->handlers = new \SplObjectStorage();
    }

    public function add(TrackedBufferHandler $handler): void
    {
        $this->handlers->attach($handler);
    }

    public function forget(TrackedBufferHandler $handler): void
    {
        $this->handlers->detach($handler);
    }

    public function count(): int
    {
        return count($this->handlers);
    }

    /** Flushes every handler holding lines, and removes each. */
    public function flushAll(): void
    {
        $handlers = [];
        foreach ($this->handlers as $handler) {
            $handlers[] = $handler;
        }
        foreach ($handlers as $handler) {
            try {
                $handler->flush();
            } catch (\Throwable) {
                // Nothing reaches the application.
            }
            $this->handlers->detach($handler);
        }
    }
}
