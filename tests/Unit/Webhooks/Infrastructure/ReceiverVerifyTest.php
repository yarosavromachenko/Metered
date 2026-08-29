<?php

declare(strict_types=1);

/*
 * The verify() the demo receiver runs, and docs/webhooks.md prints, against
 * the published vectors: the documented example is code known to work.
 */

require_once dirname(__DIR__, 4) . '/docker/webhook-receiver/verify.php';

const RECEIVER_BODY = '{"id":"01a0d974-2c1e-7f0a-9d3b-6a2e8c4f1b77","type":"invoice.paid","created_at":"2026-09-25T12:00:00+00:00","data":{"number":"INV-000042"}}';
const RECEIVER_HEADER = 't=1790337600,v1=b8bca8b24ab0180f1a9bf6ef35319860ab14523cf6ea8c662728ddc0fe87779c,v1=aea34003af96e8cc915f95d6ff893a0c876b873146fe4c942bc16bcde5675d83';

it('accepts the vectors with either secret of a rotation', function (string $secret): void {
    expect(verify(RECEIVER_BODY, RECEIVER_HEADER, $secret, 300, 1_790_337_600))->toBeTrue();
})->with(['whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', 'whsec_ZB4oqhnYVwJv0g7xjrCnHgT2cQ6N7KeL']);

it('rejects another secret, a changed body, and a delivery outside the tolerance', function (string $body, string $secret, int $now): void {
    expect(verify($body, RECEIVER_HEADER, $secret, 300, $now))->toBeFalse();
})->with([
    'another secret' => [RECEIVER_BODY, 'whsec_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 1_790_337_600],
    'a changed body' => [RECEIVER_BODY . ' ', 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', 1_790_337_600],
    'too late' => [RECEIVER_BODY, 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', 1_790_337_901],
    'too early' => [RECEIVER_BODY, 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', 1_790_337_299],
]);

it('accepts a delivery at the very edge of the tolerance', function (): void {
    expect(verify(RECEIVER_BODY, RECEIVER_HEADER, 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', 300, 1_790_337_900))->toBeTrue();
});

it('rejects a header that is not one', function (string $header): void {
    expect(verify(RECEIVER_BODY, $header, 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', 300, 1_790_337_600))->toBeFalse();
})->with(['', 't=1790337600', 'v1=b8bca8b24ab0180f1a9bf6ef35319860ab14523cf6ea8c662728ddc0fe87779c', 'garbage']);
