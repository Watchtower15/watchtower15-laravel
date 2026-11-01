<?php

declare(strict_types=1);

namespace WatchTower15\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;
use WatchTower15\Client;
use WatchTower15\Laravel\Http\LaravelTransport;
use WatchTower15\Metrics\TokenProvider;

/**
 * Every `Client` the bridge builds (D8, D15): over Laravel's HTTP client, with the SDK's logger, naming Laravel and its version
 * in the `User-Agent` once. A URL left null is left out, so the core's default applies. Stateless, so a singleton.
 *
 * @internal
 */
final class ClientFactory
{
    public function __construct(private readonly LaravelTransport $transport)
    {
    }

    public function make(
        #[\SensitiveParameter] ?string $apiKey,
        #[\SensitiveParameter] string|TokenProvider|null $metricsToken,
        #[\SensitiveParameter] ?string $logsToken,
        ?string $apiUrl,
        ?string $ingestUrl,
        LoggerInterface $logger,
    ): Client {
        $arguments = [
            'apiKey' => $apiKey,
            'metricsToken' => $metricsToken,
            'logsToken' => $logsToken,
            'httpClient' => $this->transport,
            'logger' => $logger,
        ];
        if ($apiUrl !== null) {
            $arguments['apiUrl'] = $apiUrl;
        }
        if ($ingestUrl !== null) {
            $arguments['ingestUrl'] = $ingestUrl;
        }
        $client = new Client(...$arguments);
        $app = Container::getInstance();
        $client->useFramework('laravel', $app instanceof Application ? $app->version() : 'unknown');

        return $client;
    }
}
