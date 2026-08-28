<?php

declare(strict_types=1);

use Metered\Webhooks\Infrastructure\Http\TrustedDestination;

it('is nothing when nothing is configured', function (?string $value): void {
    expect(TrustedDestination::fromConfig($value, 'production'))->toBeNull();
})->with([null, '', '   ']);

it('names exactly one host and one port, in local and demo', function (string $environment): void {
    $trusted = TrustedDestination::fromConfig(' Webhook-Receiver:8080 ', $environment);

    expect($trusted?->host)->toBe('webhook-receiver')
        ->and($trusted?->port)->toBe(8080)
        ->and($trusted?->matches('WEBHOOK-receiver', 8080))->toBeTrue()
        ->and($trusted?->matches('webhook-receiver', 8081))->toBeFalse()
        ->and($trusted?->matches('webhook-receiver.evil.example', 8080))->toBeFalse()
        ->and($trusted?->matches('postgres', 8080))->toBeFalse();
})->with(['local', 'demo']);

it('refuses to exist anywhere but local and demo, so the application does not boot', function (string $environment): void {
    TrustedDestination::fromConfig('webhook-receiver:8080', $environment);
})->with(['production', 'staging', 'testing'])->throws(RuntimeException::class, 'refused anywhere but local and demo');

it('refuses anything wider than one host and one port', function (string $value): void {
    TrustedDestination::fromConfig($value, 'local');
})->with([
    'no port' => ['webhook-receiver'],
    'a wildcard' => ['*.internal:8080'],
    'a range' => ['172.16.0.0/12:8080'],
    'two hosts' => ['a:8080,b:8080'],
    'port zero' => ['webhook-receiver:0'],
    'port too high' => ['webhook-receiver:65536'],
    'a scheme' => ['http://webhook-receiver:8080'],
])->throws(RuntimeException::class, 'must name one host and one port');

it('takes the highest port there is', function (): void {
    expect(TrustedDestination::fromConfig('receiver:65535', 'local')?->port)->toBe(65_535)
        ->and(TrustedDestination::fromConfig('10.0.0.5:1', 'demo')?->host)->toBe('10.0.0.5');
});
