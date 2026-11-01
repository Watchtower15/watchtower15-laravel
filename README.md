# watchtower15/laravel

The WatchTower15 Laravel bridge. It puts the [PHP SDK](https://github.com/watchtower15/watchtower15-php) in the Laravel container and
adds the parts that belong to a framework: a facade, metric counters bound to your own events from a config file, minute buckets
kept in a Laravel cache store and flushed by the scheduler, three Artisan commands, and a fake for your tests. Every call the SDK
makes goes through Laravel's own HTTP client, so `Http::fake()`, Telescope and Pulse see it.

Laravel 12.69 or later, or 13. PHP `^8.3`.

This repository is a read-only split of the WatchTower15 PHP SDK: pull requests are not accepted. Documentation:
https://docs.watchtower15.com. Report a vulnerability to contact@watchtower15.com with the subject "Security".

## Install

```bash
composer require watchtower15/laravel
php artisan vendor:publish --tag=wt15-config
```

The package is auto-discovered. Publishing is optional: every key but `metrics` has a default, and a published file that holds only
the keys you change is complete.

Set the credentials you use in `.env`. Each is created in the WatchTower15 console and is good for one job:

| Variable | Used for |
|---|---|
| `WT15_API_KEY` (`wt15ak_…`) | `Wt15::me()` and the `/v1` REST API. Personal: keep it out of shared code. |
| `WT15_METRICS_TOKEN` (`wt15ms_…`) | Counting and listing custom metrics. |
| `WT15_LOGS_TOKEN` (`wt15ls_…`) | Pushing log lines. |
| `WT15_API_URL`, `WT15_INGEST_URL` | The SDK's two base URLs; leave them out for the defaults. `https` only (`http` for `localhost`, `127.0.0.1` and `[::1]`). |
| `WT15_ENABLED` | `false` turns the bridge off. |

`php artisan wt15:test` checks every credential you configured. Run it after each deploy.

## Count what your application does

Every metric key must be declared in `config/wt15.php`. A key that is not declared is never counted, so a typo cannot create (and
bill) a new metric.

```php
'metrics' => [
    // a count per saved model
    'orders.shipped' => ['type' => 'count', 'on' => 'eloquent.created: App\Models\Shipment'],
    // a count per event, only when a condition holds
    'orders.refunded' => ['type' => 'count', 'on' => App\Events\OrderRefunded::class, 'when' => 'amount > 0'],
    // a count by an amount read from the event
    'orders.refunded_cents' => ['type' => 'count', 'on' => App\Events\OrderRefunded::class, 'value' => 'amount'],
    // a level written from your own code
    'queue.depth' => ['type' => 'level'],
],
```

A row has:

* `type`: `count` or `level`.
* `on`: an event class, an `eloquent.<event>: <Model>` string, or a list of them. A row with no `on` is counted by your own code.
* `when` and `value`: a dot path read from the event (or the model), a literal, a comparison string `<path> <op> <literal>`
  (`==`, `!=`, `>`, `>=`, `<`, `<=`; the literal a quoted string, a number, `true`, `false` or `null`), an invokable class name,
  or `[Class::class, ...constructor arguments]`. `value` defaults to 1 for a count.
* `after_commit`: see below.

A row that is malformed is **disabled, never thrown**: the application keeps working, `wt15:metrics` lists the row with the reason,
and the next flush names it in its log record.

From your own code:

```php
use WatchTower15\Laravel\Facades\Wt15;

Wt15::increment('orders.shipped');
Wt15::gauge('queue.depth', 42);
Wt15::me();                 // every other call goes to the SDK's Client
Wt15::client()->ingest->metrics->list();
```

`increment()` and `gauge()` never throw.

### After the commit

By default a count is written after the surrounding database transaction commits (at once when no transaction is open), so a rolled
back transaction counts nothing and no cache row is locked inside your transaction. Every write the bridge makes for you waits for
the commit this way, an undeclared key's error count included. `after_commit` set to `false` on a row, or at the top level, counts at
once instead; it is refused on a `database` cache store, where the write would hold a row lock until your commit.

## Where the buckets live

Counts are kept in one bucket per metric key per minute, in a Laravel cache store (`buffer.store`, the application's default store
when null), and sent once a minute. The store must increment atomically and give locks:

| Store | |
|---|---|
| `redis` | Supported and tested. |
| `database` | Supported on MySQL and PostgreSQL; **not tested by the package's own suite**. **Refused on SQLite**, the default of a new Laravel application: two concurrent increments make one fail at once, and a count is lost. |
| `memcached` | Supported, not tested by the package's own suite. |
| `dynamodb` | Supported, not tested live; reads go key by key, since `many()` is a capped batch. |
| `file`, `apc`, `null`, `failover` and custom stores | Refused: `file` loses counts, `apc` has no lock and is not shared between PHP-FPM and the command line. |
| `array` | Allowed under `APP_ENV=testing` only. |

A refused store disables the metrics pipe and says why: `wt15:test` and `wt15:metrics` print it, and each `wt15:flush` run that has a
metric to send prints and logs it once. Use `wt15:metrics` as the check that the store is reachable: `wt15:test` makes no store call.

Two cautions:

* **`cache:clear` and `optimize:clear` empty the default store**: a Redis store by `FLUSHDB` of its whole database, a `database`
  store by emptying its table. With `buffer.store` null, each deploy that runs them loses the open minutes' counts. Keep the buffer
  in a store of its own, on its own Redis database number or cache table, or leave the clear out.
* **Two applications on one cache store need distinct `buffer.prefix` values** (or cache prefixes). Laravel's default cache prefix
  comes from `APP_NAME`, which is `Laravel` on every fresh install. With one prefix they share buckets and the instance id, and one
  application sends the other's counts under its own token.

## Sending

There are two ways to send, and one flush at a time per store whichever starts it.

**The scheduler (the default).** With `flush.schedule` true the bridge adds `wt15:flush --scheduled` to Laravel's scheduler every
minute, in the background and on one server, so a flush never delays your own scheduled tasks. `--scheduled` always exits 0
whatever the run reports: a refused store or a WatchTower15 outage never reaches your exception handler or error tracker, and the one
record at `log.level` and the heartbeat's silence are the signals. Run by hand, `wt15:flush` exits 1 on a stop reason other than
`max_requests` and 2 when the store or a URL is refused.

Where `php artisan schedule:run` is the main process of a container that is started each minute (a Kubernetes CronJob, an ECS scheduled
task), the container stops when `schedule:run` returns, which kills the background flush: nothing is ever sent. There, run
`php artisan schedule:work` in a long-running process, or set `flush.schedule` to `false` and add the line to your own schedule in
the foreground:

```php
Schedule::command('wt15:flush --scheduled')->everyMinute()->withoutOverlapping(5)->onOneServer();
```

**After the response.** With `flush.after_response` true, the first request of each minute flushes after the response is sent. It is for
hosts with no scheduler. It never runs after an Artisan command or per queued job, so a host with only queue workers needs the
scheduler. Under PHP-FPM the child that runs the flush serves nothing else until it ends: about 40 s at the defaults while a slow
WatchTower15 is caught up (10 requests × (3 s + 1 s)), and up to 90 s. A host with a small pool keeps the scheduler. With
`artisan serve` or mod_php, keep the scheduler. On the `database` store the claim costs one `SELECT` per request and one insert a
minute.

With no valid row and no heartbeat configured, the flush does nothing and logs nothing, unless a configured row or the heartbeat is
disabled. `flush.heartbeat` names a count key incremented in every flush, so a stopped pipe shows at WatchTower15 as silence.

## Commands

| Command | |
|---|---|
| `wt15:test` | Checks each configured credential on a client of its own: the API key with `me()`, the metric token with the source's listing (the last four characters, the metrics by status, the cap, the clock skew), the log token by its format only. Exit 2 when no credential is configured or one is malformed or a URL is invalid, else 1 when a check failed or the store is refused, else 0. It never writes. |
| `wt15:metrics` | Lists the declared keys, the disabled rows with reasons, the store's verdict and the error counters still pending. `--diff` adds each key's status and type at WatchTower15, `--max-metrics=N` exits 1 above N, `--format=json`. |
| `wt15:flush` | One flush now: "disabled", "another flush is running" and "no metric configured" exit 0; the report otherwise. `--scheduled` is the scheduler's. |

## Logging

Every record the SDK writes goes to `log.channel` (null is the default channel) at `log.level`, whatever level the SDK chose, with the
SDK's own level in the context as `wt15_level`. `log.level` is one of the eight PSR-3 levels: `debug`, `info`, `notice`, `warning`,
`error`, `critical`, `alert`, `emergency`. A `log.level` that is not one, or a `log.channel` that is not a key of `logging.channels`, is
listed by `wt15:test` and `wt15:metrics` and named in the run's record (`wt15_config_problems`), and the SDK then writes at `warning`
to the default channel. A flush run writes at most one record of its own (a `Deprecation` or `Sunset` warning from the metric client
aside), and it names the disabled rows.

## The `watchtower15` log driver

The `watchtower15` log driver sends a request's, job's or command's log lines to WatchTower15 log ingest after it ends. Add a channel to
`config/logging.php`:

```php
'watchtower15' => [
    'driver' => 'watchtower15',
    'name' => 'my-app',                 // the stream name in WatchTower15; also used when the channel is part of a stack
    'level' => env('WT15_LOG_LEVEL', 'warning'),
    'buffer_limit' => 100,              // lines held before a push during the request; 0 holds every line to the end
    'replace_placeholders' => true,     // {placeholders} interpolated, as the skeleton's own channels do
],
```

and put it in a stack (`LOG_STACK=single,watchtower15`) or use it alone. **Every line is billed per GB**, so pick `level` with care.
Set `WT15_LOGS_TOKEN` (and `WT15_INGEST_URL` only for a non-default host). On a host that runs the WatchTower15 agent, the agent's file
tail is the better path.

* **When lines are sent.** At `terminating` (after the response under PHP-FPM, per request under Octane; after every Artisan command),
  after each queue job (`JobProcessed`, `JobFailed`, `JobReleasedAfterException`) and at `WorkerStopping`, and during a request or job
  for every `buffer_limit` lines past the first. A job on the `sync` connection runs inside the request that dispatched it, so its
  lines go with that request's. A long-running command that never terminates (a consumer loop, `reverb:start`) sends only at each
  `buffer_limit` lines and at its exit.
* **Laravel Vapor** (and any host that freezes the environment once the invocation has answered): the after-response push can be frozen,
  so add a middleware that calls `Log::channel('watchtower15')->getLogger()->close()` after `$next($request)`. The push then counts in
  the invocation's time.
* **The breaker's files** are in `storage/framework/cache/wt15/`. The web server's user, the queue workers, the scheduler and every
  Artisan run must be able to write there, as Laravel's own `storage/` requires.
* **Reports.** Failures never reach the application. A flush that cannot send writes one record through `wt15.log.channel` at `wt15.log.level`:
  `WatchTower15 logs: lines not sent: <reason> <lines>`, with `wt15_report`, `wt15_lines` and `wt15_until` in its context (alert on
  them). When two `watchtower15` channels share a stack with `daily`, point `wt15.log.channel` at a channel without a `watchtower15`
  member, so a report is not buffered by the second one.
* **Limits.** Log ingest takes 600 pushes a minute per token, and the driver sends one push per request, job or command that logs at
  or above the channel's level (more past `buffer_limit`), from every host on that token. Above about ten logging requests a second per
  token, lines are lost: raise the level, use a token per service, or use the agent's file tail. A held or stopped ingest drops lines
  without a request, as the core's README describes.
* **Tests.** Under `APP_ENV=testing` the lines go to a fake client and never leave, unless `wt15.fake_in_tests` is `false`.
  `Wt15::fake()` records them: `$fake->assertLogged($level, $contains)`, `$fake->assertNothingLogged()` and `$fake->loggedLines()`,
  each flushing the channels first; `assertNothingRecorded()` covers log lines too.

## Two clients

The facade's `Client` carries all three credentials. The metrics pipe has a `Client` of its own that holds the metric token and the
ingest URL alone, so a malformed API key or log token never stops the counting. `Wt15::client()` and the calls forwarded to it throw the
SDK's `ConfigurationException` for a malformed credential or URL, to their caller only.

## Testing your own code

```php
use WatchTower15\Laravel\Facades\Wt15;

$fake = Wt15::fake();

event(new OrderRefunded(amount: 5));

$fake->assertIncremented('orders.refunded');
Wt15::client()->assertCalled('public.me.view');
```

`Wt15::fake()` records counters, gauges and API calls and sends nothing. Your bindings record through it, a rolled-back transaction
records nothing, and a key that no row declares (or declares as the other type) is not recorded, as in production. The assertions are
`assertIncremented($key, $by = null)`, `assertNotIncremented($key)`, `assertGaugeSet($key, $value = null)` and `assertNothingRecorded()`;
`Wt15::client()` is the SDK's `FakeClient` for the API and ingest calls.

Without the fake, a test application (`APP_ENV=testing`) still never sends metrics: the pipe's client is a fake while
`wt15.fake_in_tests` is true (the default). Set it to `false` in the test that wants the real path; the pipe then sends through
Laravel's HTTP client, where `Http::fake()` answers. The facade's `Client` and the commands' own clients always use the HTTP client.

## Octane and queue workers

The bridge holds no request state between requests. The client, the aggregator, the buckets' store, the logger and the manager are
scoped bindings, which Octane drops after each request and a queue worker after each job; the singletons hold no state and no
container; the facade does not cache its root. No entry in `config/octane.php` is needed.

## Security

* Credentials are read from `.env`, never logged, and shown at most as their prefix and last four characters; a `wt15ms_` string never
  reaches the autoloader.
* The SDK never follows a redirect and sends credentials only to the two hosts you configure. TLS verification is Laravel's HTTP
  client default, and the bridge never turns it off, but **your `Http::globalOptions()` applies to the SDK's requests too**: a global
  `verify` of `false` turns TLS verification off for them, and a global `debug` writes each request, `Authorization` header included, to
  its stream. `http_errors` and `stream` are the exception: the bridge sets both itself.
* The `Authorization` header goes to anything that sees the request: Telescope's client-request watcher hides `authorization` by
  default (do not un-hide it); Laravel's `RequestSending`, `ResponseReceived` and `ConnectionFailed` events and `Http::recorded()`
  carry each request whole; and your `Http::globalMiddleware()` and `Http::globalRequestMiddleware()` see every request the factory
  builds, the SDK's included.
* Errors thrown by the transport never chain Laravel's or Guzzle's exception (it holds the request and its header).

## More

The core's README covers the API, the PHP support policy and the SDK's other parts: https://github.com/watchtower15/watchtower15-php.

MIT licence.
