<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Http;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\StrayRequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Http\Transport;
use WatchTower15\Http\TransportOptions;

/**
 * Every call of every `Client` the bridge builds goes through Laravel's HTTP client (D7), so `Http::fake()`,
 * `Http::preventStrayRequests()`, Telescope and Pulse see it.
 *
 * - The factory is resolved at each send, so a fake set after boot applies; the adapter holds no state and no container.
 * - The body is the core's own PSR-7 stream: a string body would leak a `/tmp` file per call in a long-running process.
 * - `http_errors` and `stream` are set false after the application's global options, so every status is a response and a body
 *   of undeclared length is read, and the response cap applies.
 * - Every failure is an `ApiConnectionException` with no `previous` (Guzzle's exception holds the request and its
 *   `Authorization` header), built from scalars only so no stack frame holds the cause as an argument.
 *
 * @internal
 */
final class LaravelTransport implements Transport
{
    public function send(#[\SensitiveParameter] RequestInterface $request, TransportOptions $options): ResponseInterface
    {
        $cap = $options->maxResponseBytes;
        $tooLarge = false;
        try {
            $response = Container::getInstance()->make(Factory::class)
                ->withHeaders($this->headersOf($request))
                ->timeout($options->timeout)
                ->connectTimeout($options->connectTimeout)
                ->withoutRedirecting()
                ->withOptions([
                    'http_errors' => false,
                    'stream' => false,
                    'on_headers' => static function (ResponseInterface $head) use ($cap, &$tooLarge): void {
                        if ((int) $head->getHeaderLine('Content-Length') > $cap) {
                            $tooLarge = true;

                            throw new \RuntimeException('response too large');
                        }
                    },
                ])
                ->send($request->getMethod(), (string) $request->getUri(), ['body' => $request->getBody()])
                ->toPsrResponse();
        } catch (\Throwable $e) {
            throw $this->failure($e::class . ': ' . $e->getMessage(), $this->wasSentBy($e), $tooLarge);
        }
        if ($tooLarge || ($response->getBody()->getSize() ?? 0) > $cap) {
            throw $this->failure('response too large', true, true);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headersOf(#[\SensitiveParameter] RequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * Whether the request may have reached the server: false for a refusal before any send and for a failure to resolve, connect
     * or negotiate TLS (cURL 6, 7, 35), true otherwise.
     */
    private function wasSentBy(#[\SensitiveParameter] \Throwable $cause): bool
    {
        if ($cause instanceof StrayRequestException) {
            return false;
        }
        $guzzle = $cause instanceof ConnectException ? $cause : $cause->getPrevious();
        if ($guzzle instanceof ConnectException) {
            // Guzzle 8: a ConnectException is a failure of the connection phase (resolve, connect, TLS, connect timeout), and carries
            // no handler context. Guzzle 7: every cURL failure is one, and the context holds cURL's errno.
            $reader = $this->contextReader();
            if (! method_exists($guzzle, $reader)) {
                return false;
            }
            $context = $guzzle->{$reader}();
            $errno = data_get($context, 'errno');
            if (in_array($errno, [6, 7, 35], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The name of Guzzle 7's handler-context method; a name the analyser cannot fold, since the method is absent from Guzzle 8.
     *
     * @return string
     */
    private function contextReader(): string
    {
        return 'getHandlerContext';
    }

    /**
     * Built from scalars: the cause is gone before this frame exists.
     */
    private function failure(string $cause, bool $wasSent, bool $tooLarge): ApiConnectionException
    {
        return new ApiConnectionException(
            $tooLarge ? 'The response was larger than the SDK reads.' : 'The request could not be completed (' . $cause . ').',
            $wasSent,
            $tooLarge,
        );
    }
}
