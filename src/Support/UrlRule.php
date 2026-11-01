<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Support;

use WatchTower15\Client;
use WatchTower15\ConfigurationException;

/**
 * D14: the core's URL rule, applied to the bridge's two base URLs before any `Client` is built with them. The rule is the
 * core's own (a `Client` is built with the URL alone), so there is one copy.
 *
 * @internal
 */
final class UrlRule
{
    /**
     * @param 'apiUrl'|'ingestUrl' $argument
     *
     * @return ?string null when the URL is usable (or not set), else why it is not; never the URL itself
     */
    public static function problem(?string $url, string $argument): ?string
    {
        if ($url === null) {
            return null;
        }
        try {
            if ($argument === 'apiUrl') {
                new Client(apiUrl: $url);
            } else {
                new Client(ingestUrl: $url);
            }
        } catch (ConfigurationException $e) {
            return trim((string) preg_replace('/`' . $argument . '`/', 'it', $e->getMessage()), ". \t\n");
        }

        return null;
    }

    public static function variable(string $argument): string
    {
        return $argument === 'apiUrl' ? 'WT15_API_URL' : 'WT15_INGEST_URL';
    }
}
