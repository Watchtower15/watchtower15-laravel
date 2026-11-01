<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use WatchTower15\Exception\ApiConnectionException;
use WatchTower15\Http\TransportOptions;
use WatchTower15\Laravel\Http\LaravelTransport;
use WatchTower15\Laravel\Tests\Support\Keys;
use WatchTower15\Laravel\Tests\TestCase;

/** LB21 and LB22: the transport adapter over Laravel's HTTP client. */
final class TransportTest extends TestCase
{
    private function request(string $body = '{"points":[]}'): RequestInterface
    {
        return new Psr7Request('POST', 'https://api.example.test/api/metric-sources/ingest', [
            'Authorization' => 'Bearer ' . Keys::metrics(),
            'Content-Type' => 'application/json',
        ], Utils::streamFor($body));
    }

    public function testARedirectIsReturnedNotFollowedAndTheOptionsAreTheCores(): void
    {
        $seen = [];
        Http::fake(static function (Request $request, array $options) use (&$seen) {
            $seen[] = $options;

            return Http::response('', 302, ['Location' => 'https://elsewhere.test/']);
        });

        $response = (new LaravelTransport())->send($this->request(), new TransportOptions(3.0, 1.0));

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(1, $seen, 'one request');
        self::assertSame(3.0, (float) $seen[0]['timeout']);
        self::assertSame(1.0, (float) $seen[0]['connect_timeout']);
        self::assertFalse($seen[0]['allow_redirects']);
        self::assertFalse($seen[0]['http_errors']);
        self::assertFalse($seen[0]['stream']);
    }

    /** @return iterable<string, array{\Closure, bool}> */
    public static function failures(): iterable
    {
        yield 'a failed connection (no handler context)' => [static fn () => Http::failedConnection(), true];
        yield 'cURL 7 (connect)' => [static fn (): \Closure => static fn (Request $request) => Create::rejectionFor(
            new ConnectException('cURL error 7: connect', $request->toPsrRequest(), null, ['errno' => 7]),
        ), false];
        yield 'cURL 6 (resolve)' => [static fn (): \Closure => static fn (Request $request) => Create::rejectionFor(
            new ConnectException('cURL error 6: resolve', $request->toPsrRequest(), null, ['errno' => 6]),
        ), false];
        yield 'cURL 28 (timeout)' => [static fn (): \Closure => static fn (Request $request) => Create::rejectionFor(
            new ConnectException('cURL error 28: timeout', $request->toPsrRequest(), null, ['errno' => 28]),
        ), true];
    }

    #[DataProvider('failures')]
    public function testEveryFailureIsAnApiConnectionExceptionWithNoPreviousAndNoSecret(\Closure $fake, bool $wasSent): void
    {
        $stub = $fake();
        Http::fake(is_callable($stub) ? $stub : static fn () => $stub);
        $body = '{"secret_body":"sentinel-body-value"}';

        try {
            (new LaravelTransport())->send($this->request($body), new TransportOptions(3.0, 1.0));
            self::fail('an ApiConnectionException was expected');
        } catch (ApiConnectionException $e) {
            self::assertNull($e->getPrevious(), 'no previous');
            self::assertSame($wasSent, $e->wasSent());
            self::assertFalse($e->isResponseTooLarge());
            foreach ([$e->getMessage(), $e->getTraceAsString(), $this->framesOnTheStack($e)] as $text) {
                self::assertStringNotContainsString(Keys::metrics(), $text, 'no Authorization value');
                self::assertStringNotContainsString('sentinel-body-value', $text, 'no body');
            }
        }
    }

    public function testAStrayRequestIsRefusedBeforeAnySendAndWasNotSent(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://other.test/*' => Http::response()]);

        try {
            (new LaravelTransport())->send($this->request(), new TransportOptions(3.0, 1.0));
            self::fail('an ApiConnectionException was expected');
        } catch (ApiConnectionException $e) {
            self::assertNull($e->getPrevious());
            self::assertFalse($e->wasSent());
            self::assertStringNotContainsString(Keys::metrics(), $e->getMessage());
        }
    }

    public function testAnAnswerPastTheCapIsRefused(): void
    {
        Http::fake(['*' => Http::response(str_repeat('a', 17 * 1024 * 1024), 200)]);

        try {
            (new LaravelTransport())->send($this->request(), new TransportOptions(3.0, 1.0));
            self::fail('an ApiConnectionException was expected');
        } catch (ApiConnectionException $e) {
            self::assertTrue($e->isResponseTooLarge());
            self::assertTrue($e->wasSent());
        }
    }

    public function testAGlobalHttpErrorsOrStreamOptionNeverTurnsAStatusIntoAnException(): void
    {
        Http::globalOptions(['http_errors' => true, 'stream' => true]);
        $seen = [];
        Http::fake(static function (Request $request, array $options) use (&$seen) {
            $seen[] = $options;

            return Http::response('{}', 401);
        });

        $response = (new LaravelTransport())->send($this->request(), new TransportOptions(3.0, 1.0));

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($seen[0]['http_errors']);
        self::assertFalse($seen[0]['stream']);
    }

    public function testTheSecretsCarryTheSensitiveParameterAttribute(): void
    {
        $class = new \ReflectionClass(LaravelTransport::class);
        foreach (['send' => 'request', 'headersOf' => 'request', 'wasSentBy' => 'cause'] as $method => $parameter) {
            $found = null;
            foreach ($class->getMethod($method)->getParameters() as $candidate) {
                if ($candidate->getName() === $parameter) {
                    $found = $candidate;
                }
            }
            self::assertNotNull($found, $method);
            self::assertNotEmpty($found->getAttributes(\SensitiveParameter::class), $method . '($' . $parameter . ')');
        }
    }

    public function testNoTempFileIsOpenedByTheAdapter(): void
    {
        Http::fake(['*' => Http::response('{}', 200)]);
        $requests = [];
        for ($i = 0; $i < 10; $i++) {
            $requests[] = $this->request(str_repeat('x', 3 * 1024 * 1024));
        }
        $before = self::tempLinks();

        foreach ($requests as $request) {
            (new LaravelTransport())->send($request, new TransportOptions(3.0, 1.0));
        }

        self::assertSame($before, self::tempLinks(), 'the only temp files are the ten bodies\' own');
    }

    /**
     * The arguments of every frame on the stack from the point the exception was built up to the adapter's `send()`: what a dump of
     * the exception would print of the request that was in flight.
     */
    private function framesOnTheStack(\Throwable $e): string
    {
        $text = '';
        foreach ($e->getTrace() as $frame) {
            $text .= print_r($frame['args'] ?? [], true);
            if (($frame['class'] ?? null) === LaravelTransport::class && $frame['function'] === 'send') {
                break;
            }
        }

        return $text;
    }

    private static function tempLinks(): int
    {
        $count = 0;
        foreach (glob('/proc/self/fd/*') ?: [] as $link) {
            $target = @readlink($link);
            if (is_string($target) && str_starts_with($target, sys_get_temp_dir() . '/php')) {
                $count++;
            }
        }

        return $count;
    }
}
