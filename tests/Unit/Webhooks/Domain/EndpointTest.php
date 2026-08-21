<?php

declare(strict_types=1);

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Webhooks\Domain\Endpoint\BreakerState;
use Metered\Webhooks\Domain\Endpoint\CircuitBreaker;
use Metered\Webhooks\Domain\Endpoint\Endpoint;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;
use Metered\Webhooks\Domain\Endpoint\EventType;
use Metered\Webhooks\Domain\Exception\InvalidEndpoint;
use Metered\Webhooks\Domain\Signing\SecretKey;

/**
 * @param list<EventType> $events
 */
function webhookEndpoint(array $events = [EventType::InvoicePaid, EventType::InvoiceFinalized]): Endpoint
{
    return Endpoint::register(
        Uuid::fromString('01924b7c-0000-7000-8000-00000000c001'),
        new TenantContext(Uuid::fromString('01924b7c-0000-7000-8000-00000000c090'), Uuid::fromString('01924b7c-0000-7000-8000-00000000c091')),
        EndpointUrl::fromString('https://hooks.example.com/metered'),
        '  Billing sync ',
        $events,
        SecretKey::fromString('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'),
        new DateTimeImmutable('2026-09-25T12:00:00Z'),
    );
}

it('reads a URL for its scheme, host and port', function (string $url, bool $allowHttp, string $host, int $port): void {
    $parsed = EndpointUrl::fromString($url, $allowHttp);

    expect($parsed->host)->toBe($host)->and($parsed->port)->toBe($port)->and((string) $parsed)->toBe(trim($url));
})->with([
    ['https://Hooks.Example.com/in?x=1', false, 'hooks.example.com', 443],
    ['https://hooks.example.com:8443/in', false, 'hooks.example.com', 8443],
    ['https://[2001:4860:4860::8888]/in', false, '2001:4860:4860::8888', 443],
    [' http://receiver.test/in ', true, 'receiver.test', 80],
]);

it('refuses a URL a delivery should never go to', function (string $url, string $reason): void {
    expect(static fn(): EndpointUrl => EndpointUrl::fromString($url))->toThrow(InvalidEndpoint::class, $reason);
})->with([
    'plain http' => ['http://hooks.example.com/in', 'over https only'],
    'another scheme' => ['ftp://hooks.example.com/in', 'over https only'],
    'relative' => ['/in', 'not an absolute URL'],
    'empty' => ['', '1 to 2048 characters'],
    'too long' => ['https://hooks.example.com/' . str_repeat('a', 2030), '1 to 2048 characters'],
    'credentials' => ['https://user:pass@hooks.example.com/in', 'must not carry credentials'],
    'fragment' => ['https://hooks.example.com/in#x', 'fragment'],
]);

it('refuses a scheme other than http even where http is allowed', function (): void {
    EndpointUrl::fromString('ftp://hooks.example.com/in', true);
})->throws(InvalidEndpoint::class, 'only http and https');

it('registers enabled, closed, with its events in a fixed order and no duplicates', function (): void {
    $endpoint = webhookEndpoint([EventType::InvoicePaid, EventType::SubscriptionCreated, EventType::InvoicePaid]);

    expect($endpoint->eventTypes)->toBe([EventType::SubscriptionCreated, EventType::InvoicePaid])
        ->and($endpoint->description)->toBe('Billing sync')
        ->and($endpoint->enabled)->toBeTrue()
        ->and($endpoint->breaker->state)->toBe(BreakerState::Closed)
        ->and($endpoint->listensTo(EventType::InvoicePaid))->toBeTrue()
        ->and($endpoint->listensTo(EventType::InvoiceVoided))->toBeFalse();
});

it('refuses an endpoint listening to nothing, or described at length', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidEndpoint::class, $message);
})->with([
    'no events' => [static fn(): Endpoint => webhookEndpoint([]), 'at least one event'],
    'long description' => [static fn(): Endpoint => webhookEndpoint()->reconfigure(EndpointUrl::fromString('https://a.example'), str_repeat('d', 256), [EventType::InvoicePaid], true), 'at most 255'],
]);

it('signs with both secrets for the grace period after a rotation, then with the new one', function (): void {
    $rotatedAt = new DateTimeImmutable('2026-09-25T12:00:00Z');
    $new = SecretKey::fromString('whsec_ZB4oqhnYVwJv0g7xjrCnHgT2cQ6N7KeL');
    $endpoint = webhookEndpoint()->rotate($new, $rotatedAt, 86_400);

    $during = $endpoint->signingSecrets(new DateTimeImmutable('2026-09-26T11:59:59Z'));
    $after = $endpoint->signingSecrets(new DateTimeImmutable('2026-09-26T12:00:00Z'));

    expect(array_map(static fn(SecretKey $s): string => $s->masked(), $during))->toBe(['whsec_…7KeL', 'whsec_…LaSw'])
        ->and(array_map(static fn(SecretKey $s): string => $s->masked(), $after))->toBe(['whsec_…7KeL'])
        ->and(webhookEndpoint()->signingSecrets($rotatedAt))->toHaveCount(1);
});

it('stops listening when disabled, and forgets the breaker when the URL changes', function (): void {
    $open = webhookEndpoint()->withBreaker(CircuitBreaker::restore(BreakerState::Open, 5, new DateTimeImmutable('2026-09-25T12:00:00Z')));

    $disabled = $open->reconfigure($open->url, 'Billing sync', [EventType::InvoicePaid], false);
    $moved = $open->reconfigure(EndpointUrl::fromString('https://new.example.com/in'), '', [EventType::InvoicePaid], true);

    expect($disabled->listensTo(EventType::InvoicePaid))->toBeFalse()
        ->and($disabled->breaker->state)->toBe(BreakerState::Open)
        ->and($moved->breaker->state)->toBe(BreakerState::Closed)
        ->and($moved->eventTypes)->toBe([EventType::InvoicePaid])
        ->and($moved->secret->masked())->toBe('whsec_…LaSw');
});

it('restores exactly what was stored', function (): void {
    $e = webhookEndpoint()->rotate(SecretKey::fromString('whsec_ZB4oqhnYVwJv0g7xjrCnHgT2cQ6N7KeL'), new DateTimeImmutable('2026-09-25T12:00:00Z'), 60);

    expect(Endpoint::restore($e->id, $e->tenant, $e->url, $e->description, $e->eventTypes, $e->secret, $e->previousSecret, $e->previousSecretExpiresAt, $e->enabled, $e->breaker, $e->createdAt))
        ->toEqual($e)
        ->and(EventType::names())->toBe(['subscription.created', 'subscription.canceled', 'invoice.finalized', 'invoice.paid', 'invoice.voided']);
});

it('takes a description of exactly the limit', function (): void {
    $endpoint = webhookEndpoint()->reconfigure(EndpointUrl::fromString('https://a.example'), str_repeat('d', 255), [EventType::InvoicePaid], true);

    expect(mb_strlen($endpoint->description))->toBe(255);
});

it('signs with one secret when no previous one is kept, whatever the stored expiry says', function (): void {
    $e = webhookEndpoint();
    $odd = Endpoint::restore($e->id, $e->tenant, $e->url, $e->description, $e->eventTypes, $e->secret, null, new DateTimeImmutable('2030-01-01'), true, $e->breaker, $e->createdAt);

    expect($odd->signingSecrets(new DateTimeImmutable('2026-09-25')))->toHaveCount(1);
});

it('takes a URL of exactly the limit, in any case of scheme', function (): void {
    $url = 'HTTPS://hooks.example.com/' . str_repeat('a', 2048 - 26);

    expect(strlen(EndpointUrl::fromString($url)->value))->toBe(2048)
        ->and(EndpointUrl::fromString($url)->scheme)->toBe('https');
});

it('refuses a URL with a user and no password, or one the parser cannot read', function (string $url, string $reason): void {
    expect(static fn(): EndpointUrl => EndpointUrl::fromString($url))->toThrow(InvalidEndpoint::class, $reason);
})->with([
    ['https://user@hooks.example.com/in', 'must not carry credentials'],
    ['https:///no-host', 'not an absolute URL'],
]);
