<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use WatchTower15\Laravel\Tests\Support\Keys;
use WatchTower15\Laravel\Tests\Support\Wire;
use WatchTower15\Laravel\Tests\TestCase;
use WatchTower15\Logs\LogShipper;

/** The `watchtower15` log driver on the real path: a logs token, `fake_in_tests` false, `Http::fake()` for log ingest. */
abstract class LogsTestCase extends TestCase
{
    protected function setUp(): void
    {
        LogShipper::reset();
        parent::setUp();
        $this->emptyBreakerDirectory();
        Wire::logs();
    }

    protected function tearDown(): void
    {
        $this->emptyBreakerDirectory();
        parent::tearDown();
    }

    protected function wt15Config(): array
    {
        return ['fake_in_tests' => false, 'logs_token' => Keys::logs(), 'metrics' => []];
    }

    protected function defineMore($app): void
    {
        $app['config']->set('logging.channels.watchtower15', ['driver' => 'watchtower15', 'name' => 'billing', 'level' => 'debug', 'replace_placeholders' => true]);
    }

    protected function breakerDirectory(): string
    {
        return $this->app->storagePath('framework/cache/wt15');
    }

    protected function emptyBreakerDirectory(): void
    {
        if (! isset($this->app)) {
            return;
        }
        foreach (glob($this->breakerDirectory() . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->breakerDirectory());
    }

    /**
     * The log ingest POSTs sent so far.
     *
     * @return list<Request>
     */
    protected function logPosts(): array
    {
        $posts = [];
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/log-sources/ingest')) {
                $posts[] = $request;
            }
        }

        return $posts;
    }

    /**
     * Every line of every log POST.
     *
     * @return list<array<string, mixed>>
     */
    protected function sentLines(): array
    {
        $lines = [];
        foreach ($this->logPosts() as $post) {
            array_push($lines, ...Wire::lines($post));
        }

        return $lines;
    }
}
