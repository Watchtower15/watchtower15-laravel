<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use WatchTower15\ConfigurationException;
use WatchTower15\Laravel\Facades\Wt15;
use WatchTower15\Laravel\Tests\Fixtures\CountingToken;
use WatchTower15\Laravel\Tests\Support\Keys;
use WatchTower15\Laravel\Tests\Support\Wire;

/** LB19 (an invalid `ingest_url` or `api_url`) and LB30 (the two base URLs reach the requests). */
final class UrlsTest extends PipeTestCase
{
    protected function wt15Config(): array
    {
        $config = parent::wt15Config();
        $config['api_key'] = Keys::apiKey();
        $config['token'] = CountingToken::class;
        $config['flush'] = ['heartbeat' => 'wt15.flush'];

        return $config;
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIngestUrls(): iterable
    {
        yield 'does not parse' => ['not a url'];
        yield 'http to a public host' => ['http://api.example.com'];
        yield 'another scheme' => ['ftp://x.test'];
    }

    #[DataProvider('invalidIngestUrls')]
    public function testAnInvalidIngestUrlKeepsTheCountsAndSendsNothing(string $url): void
    {
        $this->reconfigure(['ingest_url' => $url]);
        Wire::standard();
        $bucket = $this->bucket();
        Wt15::increment('orders.shipped');
        $this->closeBucket();

        self::assertSame(2, Artisan::call('wt15:flush'));
        self::assertStringContainsString('WT15_INGEST_URL is invalid', Artisan::output());
        self::assertSame(1, $this->counted('orders.shipped', $bucket), 'the count is kept');
        self::assertSame(0, CountingToken::$calls, 'no token resolved');
        self::assertSame([], $this->sent(), 'no HTTP call');
        self::assertSame(0, $this->counted('wt15.flush', $bucket + 1), 'no heartbeat added');
        self::assertCount(1, $this->logRecords(), 'logged once');

        self::assertSame(0, Artisan::call('wt15:flush', ['--scheduled' => true]), 'the scheduler\'s run exits 0');
        self::assertCount(2, $this->logRecords(), 'one more record');
        self::assertSame([], $this->sent());

        self::assertSame(2, Artisan::call('wt15:metrics', ['--diff' => true]));
        self::assertSame([], $this->sent());

        try {
            Wt15::me();
            self::fail('Wt15::me() should throw');
        } catch (ConfigurationException) {
            // The facade's Client is built with ingest_url, so it fails too.
        }
        self::assertSame([], $this->sent());

        CountingToken::$calls = 0;
        self::assertSame(2, Artisan::call('wt15:test'));
        $output = Artisan::output();
        self::assertStringContainsString('not checked: WT15_INGEST_URL is invalid', $output);
        self::assertSame(1, CountingToken::$calls, 'resolved for its format check only');
        $sent = $this->sent();
        self::assertCount(1, $sent, 'the pass\'s only request');
        self::assertStringEndsWith('/v1/me', $sent[0]->url());
    }

    public function testLocalhostIsValidAndSends(): void
    {
        $this->reconfigure(['ingest_url' => 'http://localhost:8080']);
        Wire::standard();
        Wt15::increment('orders.shipped');
        $this->closeBucket();

        self::assertSame(0, Artisan::call('wt15:flush'));

        $posts = $this->posts();
        self::assertCount(1, $posts);
        self::assertSame('http://localhost:8080/api/metric-sources/ingest', $posts[0]->url());
    }

    public function testAnInvalidApiUrlFailsOnlyTheFacadeClient(): void
    {
        $this->reconfigure(['api_url' => 'http://api.example.com']);
        Wire::standard();
        Wt15::increment('orders.shipped');
        $this->closeBucket();

        self::assertSame(0, Artisan::call('wt15:flush'));
        self::assertCount(1, $this->posts(), 'the pipe sends');

        try {
            Wt15::me();
            self::fail('Wt15::me() should throw');
        } catch (ConfigurationException) {
            // Expected.
        }

        Http::swap(new Factory());
        Wire::standard();
        self::assertSame(2, Artisan::call('wt15:test'));
        self::assertStringContainsString('WT15_API_URL', Artisan::output());
        $urls = array_map(static fn ($r): string => $r->url(), $this->sent());
        self::assertCount(1, $urls, 'the ingest GET, and no /v1/me');
        self::assertStringEndsWith('/api/metric-sources/ingest', $urls[0]);
    }

    public function testTheDefaultsAndTheGivenBasesReachTheRequests(): void
    {
        Wire::standard();
        Wt15::me();
        Wt15::increment('orders.shipped');
        $this->closeBucket();
        Artisan::call('wt15:flush');

        $urls = array_map(static fn ($r): string => $r->url(), $this->sent());
        self::assertContains('https://rest.watchtower15.com/v1/me', $urls);
        self::assertContains('https://api.watchtower15.com/api/metric-sources/ingest', $urls);

        $this->reconfigure(['api_url' => 'https://example.test', 'ingest_url' => 'https://example.test']);
        Http::swap(new Factory());
        Wire::standard();
        Wt15::me();
        Wt15::increment('orders.shipped');
        $this->closeBucket();
        Artisan::call('wt15:flush');

        $urls = array_map(static fn ($r): string => $r->url(), $this->sent());
        self::assertContains('https://example.test/me', $urls);
        self::assertContains('https://example.test/api/metric-sources/ingest', $urls);
    }
}
