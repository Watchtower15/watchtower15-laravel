<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use WatchTower15\Laravel\Tests\RedisTestCase;
use WatchTower15\Laravel\Tests\Support\Keys;
use WatchTower15\Laravel\Tests\Support\Wire;

/** The real path on SDK03's Redis: `wt15.fake_in_tests` false, a well-formed metric token, and `Http::fake()` for the ingest. */
abstract class PipeTestCase extends RedisTestCase
{
    protected function wt15Config(): array
    {
        return [
            'fake_in_tests' => false,
            'token' => Keys::metrics(),
            'metrics' => [
                'orders.shipped' => ['type' => 'count'],
                'queue.depth' => ['type' => 'level'],
            ],
        ];
    }

    /** Moves past the current bucket's close, so it is due. */
    protected function closeBucket(): void
    {
        $this->travel(65)->seconds();
    }

    /**
     * The ingest POSTs sent so far.
     *
     * @return list<Request>
     */
    protected function posts(): array
    {
        $posts = [];
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST') {
                $posts[] = $request;
            }
        }

        return $posts;
    }

    /**
     * Every request sent so far.
     *
     * @return list<Request>
     */
    protected function sent(): array
    {
        $all = [];
        foreach (Http::recorded() as [$request]) {
            $all[] = $request;
        }

        return $all;
    }

    /**
     * The points of every POST, flattened.
     *
     * @return list<array<string, mixed>>
     */
    protected function postedPoints(): array
    {
        $points = [];
        foreach ($this->posts() as $post) {
            array_push($points, ...Wire::points($post));
        }

        return $points;
    }
}
