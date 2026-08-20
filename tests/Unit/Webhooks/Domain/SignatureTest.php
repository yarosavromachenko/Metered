<?php

declare(strict_types=1);

use Metered\Webhooks\Domain\Exception\InvalidEndpoint;
use Metered\Webhooks\Domain\Signing\SecretKey;
use Metered\Webhooks\Domain\Signing\Signature;

/*
 * The test vectors published in docs/webhooks.md. A receiver in any language
 * can check its verifier against them; they are pinned here so the signer can
 * never drift from what the documentation promises.
 */

const VECTOR_BODY = '{"id":"01a0d974-2c1e-7f0a-9d3b-6a2e8c4f1b77","type":"invoice.paid","created_at":"2026-09-25T12:00:00+00:00","data":{"number":"INV-000042"}}';
const VECTOR_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
const VECTOR_PREVIOUS = 'whsec_ZB4oqhnYVwJv0g7xjrCnHgT2cQ6N7KeL';

it('signs "<t>.<raw body>" with HMAC-SHA256, as the documentation’s vector says', function (): void {
    expect(Signature::header(VECTOR_BODY, new DateTimeImmutable('@1790337600'), [SecretKey::fromString(VECTOR_SECRET)]))
        ->toBe('t=1790337600,v1=b8bca8b24ab0180f1a9bf6ef35319860ab14523cf6ea8c662728ddc0fe87779c');
});

it('carries one v1 per active secret during a rotation, the current one first', function (): void {
    expect(Signature::header(VECTOR_BODY, new DateTimeImmutable('2026-09-25T12:00:00Z'), [SecretKey::fromString(VECTOR_SECRET), SecretKey::fromString(VECTOR_PREVIOUS)]))
        ->toBe('t=1790337600,v1=b8bca8b24ab0180f1a9bf6ef35319860ab14523cf6ea8c662728ddc0fe87779c,v1=aea34003af96e8cc915f95d6ff893a0c876b873146fe4c942bc16bcde5675d83');
});

it('signs an empty body too', function (): void {
    expect(Signature::header('', new DateTimeImmutable('@1790337600'), [SecretKey::fromString(VECTOR_SECRET)]))
        ->toBe('t=1790337600,v1=37e300d82f207cbd32a2212ca7f2a0056ed6b0209810ee386153246393b4c39f');
});

it('changes when a single byte of the body does', function (): void {
    $secret = [SecretKey::fromString(VECTOR_SECRET)];
    $at = new DateTimeImmutable('@1790337600');

    expect(Signature::header(VECTOR_BODY . ' ', $at, $secret))->not->toBe(Signature::header(VECTOR_BODY, $at, $secret));
});

it('accepts a secret only in its own shape, and shows it masked', function (): void {
    $secret = SecretKey::fromString(VECTOR_SECRET);

    expect($secret->reveal())->toBe(VECTOR_SECRET)
        ->and($secret->masked())->toBe('whsec_…LaSw')
        ->and($secret->equals(SecretKey::fromString(VECTOR_SECRET)))->toBeTrue()
        ->and($secret->equals(SecretKey::fromString(VECTOR_PREVIOUS)))->toBeFalse();
});

it('refuses a secret that is not one', function (string $value): void {
    SecretKey::fromString($value);
})->with([
    'no prefix' => ['MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSwxx'],
    'too short' => ['whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaS'],
    'not url-safe' => ['whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLa/+'],
    'padded' => [' ' . VECTOR_SECRET],
])->throws(InvalidEndpoint::class, 'whsec_');
