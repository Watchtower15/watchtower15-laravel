<?php

declare(strict_types=1);

/*
 * WatchTower15: the Laravel bridge's configuration.
 *
 * Publish it with `php artisan vendor:publish --tag=wt15-config`. Every key but `metrics` is merged over these defaults, so a
 * file that holds only the keys you change is complete. Unknown keys are ignored.
 */

return [
    // false: listeners stay registered and return at once, nothing is buffered, and the commands say "disabled".
    'enabled' => env('WT15_ENABLED', true),

    // The three credentials. Each is created in the console and used for one job (see the README).
    'api_key' => env('WT15_API_KEY'),
    // The metric source token: a `wt15ms_…` string, or the name of an invokable class that returns one.
    'token' => env('WT15_METRICS_TOKEN', ''),
    'logs_token' => env('WT15_LOGS_TOKEN'),

    // The SDK's two base URLs; null is the SDK's default. https only (http for localhost, 127.0.0.1 and [::1]).
    'api_url' => env('WT15_API_URL'),
    'ingest_url' => env('WT15_INGEST_URL'),

    // Count after the surrounding database transaction commits. A row can set its own.
    'after_commit' => true,

    // Under APP_ENV=testing the metrics pipe sends into a fake client. false: it sends through Laravel's HTTP client.
    'fake_in_tests' => true,

    'buffer' => [
        // The cache store that holds the minute buckets; null is the application's default store.
        'store' => null,
        // Two applications on one cache store need distinct prefixes.
        'prefix' => 'wt15',
    ],

    'flush' => [
        // A background `wt15:flush --scheduled` every minute, from Laravel's scheduler.
        'schedule' => true,
        // Also flush after the response, at most once a minute per store (for hosts with no scheduler).
        'after_response' => false,
        // A count key incremented in every flush, so a stopped pipe shows as silence; null for none.
        'heartbeat' => null,
        'max_buckets' => 60,
        'max_requests' => 10,
        'timeout' => 3,
    ],

    'log' => [
        // A key of config/logging.php's `channels`; null is the application's default channel.
        'channel' => null,
        // The level every record of the SDK is written at: debug, info, notice, warning, error, critical, alert or emergency.
        'level' => 'warning',
    ],

    // Every metric key must be declared here: a key that is not declared is never counted.
    'metrics' => [
        // 'orders.shipped' => ['type' => 'count', 'on' => 'eloquent.created: App\Models\Shipment'],
        // 'orders.refunded' => ['type' => 'count', 'on' => App\Events\OrderRefunded::class, 'when' => 'amount > 0'],
    ],
];
