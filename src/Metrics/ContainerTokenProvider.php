<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Metrics;

use Illuminate\Container\Container;
use WatchTower15\Laravel\Config\Settings;
use WatchTower15\Metrics\TokenProvider;

/**
 * D3: the metric source token. A string that starts with `wt15ms_` is the token and never reaches an autoloader; otherwise an
 * invokable class (built through the container); any other string is the token itself, which the core refuses as malformed; an
 * empty value means no token. There is no memo here: the core keeps a valid answer for at most 300 s and forgets it on a refusal.
 *
 * Stateless, so a singleton: it reaches the container when asked.
 *
 * @internal
 */
final class ContainerTokenProvider implements TokenProvider
{
    private const PREFIX = 'wt15ms_';

    public function token(): string
    {
        $container = Container::getInstance();
        $configured = $container->make(Settings::class)->token();
        if ($configured === '' || str_starts_with($configured, self::PREFIX)) {
            return $configured;
        }
        if (class_exists($configured) && method_exists($configured, '__invoke')) {
            $provider = $container->make($configured);
            if (is_callable($provider)) {
                $token = $provider();

                return is_string($token) ? $token : '';
            }
        }

        return $configured;
    }
}
