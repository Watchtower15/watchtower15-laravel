<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use WatchTower15\Testing\MockTransport;

/** The answers WatchTower15 gives, for `Http::fake()`. */
final class Wire
{
    /**
     * Ingest accepts every point of a POST; a GET lists the given metrics; `/v1/me` answers a key; `$post`, `$get` and `$me` replace those answers.
     *
     * @param list<array{key: string, type: string, status: string}> $metrics
     */
    public static function standard(?callable $post = null, array $metrics = [], int $max = 50, int $used = 0, ?callable $me = null, ?callable $get = null): void
    {
        Http::fake([
            '*/api/metric-sources/ingest' => static function (Request $request) use ($post, $get, $metrics, $max, $used): PromiseInterface {
                if ($request->method() === 'GET') {
                    return $get !== null ? $get($request) : self::listing($metrics, $max, $used);
                }

                return $post !== null ? $post($request) : self::accepted(count(self::points($request)));
            },
            '*/me' => static fn (Request $request): PromiseInterface => $me !== null ? $me($request) : self::me(),
        ]);
    }

    /** @return list<array<string, mixed>> the points of an ingest POST */
    public static function points(Request $request): array
    {
        $raw = $request->body();
        $text = str_starts_with($raw, "\x1f\x8b") ? (string) gzdecode($raw) : $raw;
        $body = json_decode($text, true);

        return is_array($body) && is_array($body['points'] ?? null) ? array_values($body['points']) : [];
    }

    public static function accepted(int $points): PromiseInterface
    {
        return self::from(MockTransport::metricsAccepted($points));
    }

    /** @param list<array{key: string, type: string, status: string}> $metrics */
    public static function listing(array $metrics = [], int $max = 50, int $used = 0): PromiseInterface
    {
        $rows = [];
        foreach ($metrics as $i => $metric) {
            $rows[] = ['uuid' => '00000000-0000-4000-8000-' . str_pad((string) $i, 12, '0', STR_PAD_LEFT)] + $metric;
        }

        return self::from(MockTransport::envelope(200, ['metrics' => $rows, 'limits' => ['max_metrics' => $max, 'used' => $used]], 'ok', ['Date' => gmdate('D, d M Y H:i:s') . ' GMT']));
    }

    public static function forbidden(): PromiseInterface
    {
        return self::from(MockTransport::envelope(403, [], 'forbidden'));
    }

    public static function unavailable(): PromiseInterface
    {
        return self::from(MockTransport::problem(503, 'service_unavailable'));
    }

    public static function me(): PromiseInterface
    {
        return self::from(MockTransport::envelope(200, [
            'key' => ['uuid' => 'k0000000-0000-4000-8000-000000000001', 'name' => 'ci key', 'last_four' => 'aaaa', 'scopes' => ['public:read'], 'created_at' => '2026-10-01T00:00:00Z', 'expires_at' => null],
            'user' => ['uuid' => 'u0000000-0000-4000-8000-000000000001', 'name' => 'Ada', 'email' => 'ada@example.com'],
            'organisation' => ['uuid' => 'o0000000-0000-4000-8000-000000000001', 'name' => 'Acme'],
        ], 'ok', ['Date' => gmdate('D, d M Y H:i:s') . ' GMT']));
    }

    private static function from(ResponseInterface $response): PromiseInterface
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = $values[0] ?? '';
        }

        return Http::response((string) $response->getBody(), $response->getStatusCode(), $headers);
    }
}
