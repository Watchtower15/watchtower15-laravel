<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Logs;

use Monolog\Handler\BufferHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog's `BufferHandler`, reporting to `BufferedChannels` while its buffer holds lines, so the application's request, job and
 * command ends can send them (D10).
 *
 * @internal
 */
final class TrackedBufferHandler extends BufferHandler
{
    public function __construct(
        HandlerInterface $handler,
        private readonly BufferedChannels $channels,
        int $bufferLimit,
        int|string|Level $level,
        bool $bubble,
    ) {
        parent::__construct($handler, $bufferLimit, $level, $bubble, true);
    }

    public function handle(LogRecord $record): bool
    {
        $result = parent::handle($record);
        if ($this->buffer !== []) {
            $this->channels->add($this);
        }

        return $result;
    }

    public function flush(): void
    {
        parent::flush();
        $this->channels->forget($this);
    }
}
