<?php

declare(strict_types=1);

use Metered\Tenancy\Domain\ApiKeySecret;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Domain\Exception\MalformedApiKey;

it('generates a token whose environment is visible in it', function (Environment $environment): void {
    $secret = ApiKeySecret::generate($environment);

    expect($secret->reveal())->toStartWith('mk_' . $environment->value . '_')
        ->and($secret->environment())->toBe($environment)
        ->and($secret->prefix())->toHaveLength(8)
        ->and($secret->reveal())->toMatch('/^mk_(live|test)_[0-9a-f]{8}_[0-9a-f]{48}$/');
})->with([Environment::Live, Environment::Test]);

it('generates a different secret every time', function (): void {
    $secrets = array_map(
        static fn(): string => ApiKeySecret::generate(Environment::Test)->reveal(),
        range(1, 50),
    );

    expect(array_unique($secrets))->toHaveCount(50);
});

it('reads back a token a client presented', function (): void {
    $generated = ApiKeySecret::generate(Environment::Live);
    $parsed = ApiKeySecret::fromToken($generated->reveal());

    expect($parsed->prefix())->toBe($generated->prefix())
        ->and($parsed->environment())->toBe(Environment::Live)
        ->and($parsed->hash())->toBe($generated->hash());
});

it('refuses a token that is not one of ours', function (string $token): void {
    expect(static fn(): ApiKeySecret => ApiKeySecret::fromToken($token))
        ->toThrow(MalformedApiKey::class);
})->with([
    'sk_live_7f3a1b2c_' . str_repeat('a', 48),          // another vendor's scheme
    'mk_prod_7f3a1b2c_' . str_repeat('a', 48),          // an environment we do not have
    'mk_live_7f3a_' . str_repeat('a', 48),              // prefix too short
    'mk_live_7f3a1b2c_' . str_repeat('a', 47),          // secret too short
    'mk_live_7F3A1B2C_' . str_repeat('a', 48),          // hex is lowercase here
    'mk_live_7f3a1b2c_' . str_repeat('z', 48),          // not hex at all
    'mk_live_7f3a1b2c',
    'mk_live',
    '',
]);

it('hashes the whole token, so a prefix cannot be replayed against another key', function (): void {
    $secret = ApiKeySecret::generate(Environment::Test);

    expect($secret->hash())->toBe(hash('sha256', $secret->reveal()))
        ->and($secret->hash())->toHaveLength(64)
        ->and($secret->hash())->not->toContain(substr($secret->reveal(), -48));
});

it('keeps the plaintext out of everything that prints an object', function (): void {
    $secret = ApiKeySecret::generate(Environment::Live);
    $plaintext = substr($secret->reveal(), -48);

    ob_start();
    var_dump($secret);
    $dumped = (string) ob_get_clean();

    $printed = print_r($secret, true) . var_export($secret, true) . json_encode($secret) . $dumped;

    expect($printed)->not->toContain($plaintext)
        ->and($dumped)->toContain('redacted')
        // Not Stringable and not JsonSerializable on purpose: interpolating
        // the object into a log line has to be a visible mistake, not a quiet
        // disclosure.
        ->and($secret)->not->toBeInstanceOf(Stringable::class)
        ->and($secret)->not->toBeInstanceOf(JsonSerializable::class);
});

it('refuses to be serialized into a queue payload or a session', function (): void {
    expect(static fn(): string => serialize(ApiKeySecret::generate(Environment::Live)))
        ->toThrow(Exception::class, 'not allowed');
});
