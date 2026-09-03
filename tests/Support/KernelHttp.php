<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Sends the HTTP client's requests for one host into this application's own
 * kernel, so code that drives the API over the network — the simulation —
 * can be tested against the real routes, middleware, validation and database
 * without a server.
 */
final class KernelHttp
{
    /**
     * @param  array<string, mixed>  $others  further Http::fake() stubs
     */
    public static function route(string $host, array $others = []): void
    {
        Http::fake([$host . '/*' => self::handle(...), ...$others]);
    }

    private static function handle(ClientRequest $request): PromiseInterface
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($request->headers() as $name => $values) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = implode(', ', array_filter(is_array($values) ? $values : [$values], is_string(...)));
        }

        $response = app(Kernel::class)->handle(Request::createFromBase(
            SymfonyRequest::create($request->url(), $request->method(), [], [], [], $server, $request->body()),
        ));

        return Http::response((string) $response->getContent(), $response->getStatusCode(), $response->headers->all());
    }
}
