<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Metered\Webhooks\Domain\Delivery\Verdict;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;
use Metered\Webhooks\Infrastructure\Http\GuardedTransport;
use Metered\Webhooks\Infrastructure\Http\TrustedDestination;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\ScriptedResolver;

/*
 * The SSRF guard, against a scripted DNS and a mocked network. Nothing here
 * opens a socket; what is asserted is what would have been sent, where, and
 * what was refused before anything was.
 */

/**
 * Where Guzzle's history middleware writes each request it lets through.
 *
 * @return ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<array-key, mixed>}>
 */
function requestLog(): ArrayObject
{
    return new ArrayObject();
}

/**
 * @param list<Response|Throwable> $responses
 * @param ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<array-key, mixed>}> $history
 */
function guardedTransport(ScriptedResolver $resolver, array $responses, ArrayObject $history, ?TrustedDestination $trusted = null): GuardedTransport
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new GuardedTransport($resolver, new Client(['handler' => $stack]), $trusted);
}

/**
 * What the first request was sent with.
 *
 * @param ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<array-key, mixed>}> $history
 *
 * @return array<array-key, mixed>
 */
function sentOptions(ArrayObject $history): array
{
    return $history->getArrayCopy()[0]['options'] ?? [];
}

/**
 * @param ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<array-key, mixed>}> $history
 *
 * @return list<string>
 */
function pinnedAddresses(ArrayObject $history): array
{
    $curl = sentOptions($history)['curl'] ?? null;
    $pinned = is_array($curl) ? ($curl[CURLOPT_RESOLVE] ?? null) : null;

    return is_array($pinned) ? array_values(array_filter($pinned, is_string(...))) : [];
}

it('delivers to a public address, pinned to the address it checked', function (): void {
    $history = requestLog();
    $resolver = new ScriptedResolver([['93.184.216.34']]);

    $result = guardedTransport($resolver, [new Response(204)], $history)
        ->send(EndpointUrl::fromString('https://hooks.example.com/in'), ['X-Metered-Signature' => 't=1,v1=a'], '{"id":"x"}');

    $request = $history->getArrayCopy()[0]['request'] ?? null;

    expect($result->statusCode)->toBe(204)
        ->and($result->verdict())->toBe(Verdict::Delivered)
        ->and(pinnedAddresses($history))->toBe(['hooks.example.com:443:93.184.216.34'])
        ->and($request instanceof RequestInterface ? (string) $request->getBody() : null)->toBe('{"id":"x"}')
        ->and($request instanceof RequestInterface ? $request->getHeaderLine('X-Metered-Signature') : null)->toBe('t=1,v1=a')
        ->and(sentOptions($history)['allow_redirects'] ?? null)->toBeFalse()
        ->and(sentOptions($history)['proxy'] ?? null)->toBe('')
        ->and(sentOptions($history)['connect_timeout'] ?? null)->toBe(5.0)
        ->and(sentOptions($history)['timeout'] ?? null)->toBe(10.0);
});

it('refuses, without sending anything, a host that resolves somewhere private', function (array $answer, string $ip): void {
    /** @var list<string> $answer */
    $history = requestLog();

    $result = guardedTransport(new ScriptedResolver([$answer]), [new Response(200)], $history)
        ->send(EndpointUrl::fromString('https://hooks.example.com/in'), [], '{}');

    expect($result->refusedDestination)->toBeTrue()
        ->and($result->verdict())->toBe(Verdict::GiveUp)
        ->and($result->error)->toBe(sprintf('hooks.example.com resolves to %s, which webhooks may not reach', $ip))
        ->and($history)->toHaveCount(0);
})->with([
    'a private address' => [['10.0.0.7'], '10.0.0.7'],
    'the metadata service' => [['169.254.169.254'], '169.254.169.254'],
    'loopback over IPv6' => [['::1'], '::1'],
    'one public record and one private' => [['93.184.216.34', '192.168.1.10'], '192.168.1.10'],
]);

it('refuses a URL that names a private address outright, without asking DNS', function (): void {
    $history = requestLog();
    $resolver = new ScriptedResolver([]);

    $result = guardedTransport($resolver, [new Response(200)], $history)
        ->send(EndpointUrl::fromString('https://127.0.0.1:8443/in'), [], '{}');

    expect($result->refusedDestination)->toBeTrue()
        ->and($resolver->asked)->toBe([])
        ->and($history)->toHaveCount(0);
});

it('asks DNS once, so a rebinding answer never reaches the connection', function (): void {
    $history = requestLog();
    // Public for the check, the metadata service for anyone who asks again.
    $resolver = new ScriptedResolver([['93.184.216.34'], ['169.254.169.254']]);

    guardedTransport($resolver, [new Response(200)], $history)
        ->send(EndpointUrl::fromString('https://rebind.example.com/in'), [], '{}');

    expect($resolver->asked)->toBe(['rebind.example.com'])
        ->and(pinnedAddresses($history))->toBe(['rebind.example.com:443:93.184.216.34']);
});

it('pins an IPv6 address in the brackets curl expects', function (): void {
    $history = requestLog();

    guardedTransport(new ScriptedResolver([['2606:4700:4700::1111']]), [new Response(200)], $history)
        ->send(EndpointUrl::fromString('https://hooks.example.com:8443/in'), [], '{}');

    expect(pinnedAddresses($history))->toBe(['hooks.example.com:8443:[2606:4700:4700::1111]']);
});

it('does not follow a redirect, and gives the delivery up', function (): void {
    $history = requestLog();

    $result = guardedTransport(new ScriptedResolver([['93.184.216.34']]), [
        new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        new Response(200),
    ], $history)->send(EndpointUrl::fromString('https://hooks.example.com/in'), [], '{}');

    expect($history)->toHaveCount(1)
        ->and($result->statusCode)->toBe(302)
        ->and($result->verdict())->toBe(Verdict::GiveUp);
});

it('reads a kilobyte of the answer and no more', function (): void {
    $history = requestLog();

    $result = guardedTransport(new ScriptedResolver([['93.184.216.34']]), [new Response(500, [], str_repeat('x', 1_000_000))], $history)
        ->send(EndpointUrl::fromString('https://hooks.example.com/in'), [], '{}');

    expect(strlen($result->responseExcerpt))->toBe(1024)
        ->and($result->verdict())->toBe(Verdict::Retry);
});

it('reports a receiver it could not reach, and a host that does not resolve, as worth retrying', function (): void {
    $history = requestLog();
    $transport = guardedTransport(new ScriptedResolver([['93.184.216.34'], []]), [
        new ConnectException('Connection timed out after 5000 milliseconds', new Request('POST', 'https://hooks.example.com/in')),
    ], $history);

    $timedOut = $transport->send(EndpointUrl::fromString('https://hooks.example.com/in'), [], '{}');
    $unresolved = $transport->send(EndpointUrl::fromString('https://nowhere.example.com/in'), [], '{}');

    expect($timedOut->statusCode)->toBeNull()
        ->and($timedOut->error)->toBe('Connection timed out after 5000 milliseconds')
        ->and($timedOut->verdict())->toBe(Verdict::Retry)
        ->and($unresolved->error)->toBe('nowhere.example.com does not resolve')
        ->and($unresolved->verdict())->toBe(Verdict::Retry);
});

it('sends through cURL, with options cURL accepts, when no handler is given', function (): void {
    // The real handler, pointed at a public address with a timeout too short
    // to reach it: whatever the network, the attempt fails on the clock and
    // not because Guzzle refused an option or chose a handler that cannot pin
    // the address. Both happened, and a mocked handler hid both.
    $transport = new GuardedTransport(new ScriptedResolver([["93.184.216.34"]]), connectTimeout: 0.001, timeout: 0.001);

    $result = $transport->send(EndpointUrl::fromString('https://hooks.example.com/in'), [], '{}');

    expect($result->statusCode)->toBeNull()
        ->and($result->error)->not->toContain('not supported')
        ->and($result->error)->not->toContain('stream handler')
        ->and($result->verdict())->toBe(Verdict::Retry);
});

it('connects directly even when the environment names a proxy', function (): void {
    // A proxy resolves the host itself, so the pinned address would mean
    // nothing. The real cURL handler, and two loopback addresses that both
    // refuse at once: the error names whichever one cURL actually dialled —
    // the receiver by its name, or the proxy by its address.
    // No network, and no timeout racing the refusal.
    $previous = getenv('https_proxy');
    putenv('https_proxy=http://127.0.0.1:9');

    try {
        $transport = new GuardedTransport(
            new ScriptedResolver([['127.0.0.3']]),
            trusted: TrustedDestination::fromConfig('receiver.test:9', 'local'),
            connectTimeout: 2.0,
            timeout: 2.0,
        );

        $result = $transport->send(EndpointUrl::fromString('https://receiver.test:9/in'), [], '{}');
    } finally {
        putenv($previous === false ? 'https_proxy' : 'https_proxy=' . $previous);
    }

    expect($result->statusCode)->toBeNull()
        ->and($result->error)->toContain('Failed to connect to receiver.test port 9')
        ->and($result->error)->not->toContain('127.0.0.1');
});

it('lets the configured demo receiver through, still resolved once and pinned', function (): void {
    $history = requestLog();
    $resolver = new ScriptedResolver([['172.18.0.9'], ['169.254.169.254']]);
    $transport = guardedTransport($resolver, [new Response(204)], $history, TrustedDestination::fromConfig('webhook-receiver:8080', 'local'));

    $result = $transport->send(EndpointUrl::fromString('http://webhook-receiver:8080/ok', true), [], '{}');

    expect($result->statusCode)->toBe(204)
        ->and($resolver->asked)->toBe(['webhook-receiver'])
        ->and(pinnedAddresses($history))->toBe(['webhook-receiver:8080:172.18.0.9']);
});

it('still refuses every other private destination while a receiver is trusted', function (string $url, string $address): void {
    $history = requestLog();
    $transport = guardedTransport(new ScriptedResolver([[$address]]), [new Response(204)], $history, TrustedDestination::fromConfig('webhook-receiver:8080', 'local'));

    $result = $transport->send(EndpointUrl::fromString($url, true), [], '{}');

    expect($result->refusedDestination)->toBeTrue()
        ->and($history)->toHaveCount(0);
})->with([
    'the receiver on another port' => ['http://webhook-receiver:9000/', '172.18.0.9'],
    'the database next to it' => ['http://postgres:5432/', '172.18.0.3'],
    'a lookalike name' => ['http://webhook-receiver.example.com:8080/', '10.0.0.1'],
    'the metadata service' => ['http://169.254.169.254:8080/', '169.254.169.254'],
]);
