<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use WatchTower15\Laravel\Tests\Support\Keys;

/** LG26, LG27 and LG31: a channel with a client that cannot be built, and the package's requirements. */
final class LogConfigTest extends LogsTestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function brokenClients(): iterable
    {
        yield 'a malformed logs token' => [['logs_token' => 'wt15ls_0123456789']];
        yield 'an ingest url the core refuses' => [['ingest_url' => 'http://example.com']];
    }

    /** @param array<string, mixed> $config */
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenClients')]
    public function testLg26AndLg27NothingIsThrownSentOrLoggedAsAnEmergency(array $config): void
    {
        $this->reconfigure($config);

        Log::channel('watchtower15')->error('a line');
        $this->app->terminate();

        Http::assertNothingSent();
        $records = $this->logRecords();
        self::assertCount(1, $records, 'one report');
        self::assertSame(Level::Warning, $records[0]->level);
        self::assertSame('WatchTower15 logs: lines not sent: config 1', $records[0]->message);
        self::assertSame([], array_filter($records, static fn ($r): bool => $r->level === Level::Emergency));
        self::assertFileExists($this->breakerDirectory() . '/wt15-logs-none.reported');
    }

    public function testLg31TheBridgeRequiresWhatTheDriverCalls(): void
    {
        $json = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        self::assertIsArray($json);

        self::assertSame('^3.0', $json['require']['monolog/monolog']);
        self::assertSame($json['require']['illuminate/log'], $json['require']['illuminate/queue']);
        self::assertNotEmpty(Keys::logs());
    }
}
