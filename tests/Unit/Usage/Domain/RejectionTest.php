<?php

declare(strict_types=1);

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Usage\Domain\Rejection;
use Metered\Usage\Domain\RejectionReason;

const REJECTION_ID = '01924b7c-0000-7000-8000-000000000201';
const REJECTION_ORG_ID = '01924b7c-0000-7000-8000-000000000202';
const REJECTION_PROJECT_ID = '01924b7c-0000-7000-8000-000000000203';

it('records why an event was not counted, with the event as it arrived', function (): void {
    $rejection = reject(RejectionReason::UnknownMeter, ['event_id' => 'evt_1', 'meter_code' => 'api.reqests']);

    expect($rejection->reason)->toBe(RejectionReason::UnknownMeter)
        ->and($rejection->eventId)->toBe('evt_1')
        ->and($rejection->payload)->toBe(['event_id' => 'evt_1', 'meter_code' => 'api.reqests'])
        ->and($rejection->tenant->projectId->value)->toBe(REJECTION_PROJECT_ID);
});

it('survives an event with no id at all, which is the malformed case', function (): void {
    // The one rejection that cannot name the event it is about: a message
    // that could not be read as an event has no id to read either.
    $rejection = reject(RejectionReason::Malformed, ['quantity' => '1']);

    expect($rejection->eventId)->toBeNull()
        ->and($rejection->reason)->toBe(RejectionReason::Malformed);
});

it('takes the id out of the payload only when it is a usable string', function (mixed $given, ?string $expected): void {
    expect(reject(RejectionReason::Malformed, ['event_id' => $given])->eventId)->toBe($expected);
})->with([
    'a string' => ['evt_1', 'evt_1'],
    'a padded string' => ['  evt_1 ', 'evt_1'],
    'a number the client sent unquoted' => [42, '42'],
    'an empty string' => ['', null],
    'a nested structure' => [['evt_1'], null],
    'null' => [null, null],
    'longer than the column' => [str_repeat('e', 200), null],
]);

it('says what went wrong in words the tenant can act on', function (): void {
    $rejection = reject(RejectionReason::TooOld, [], 'Older than the seven day acceptance window.');

    expect($rejection->detail)->toBe('Older than the seven day acceptance window.');
});

/**
 * @param  array<string, mixed>  $payload
 */
function reject(RejectionReason $reason, array $payload, string $detail = 'Rejected.'): Rejection
{
    return Rejection::of(
        Uuid::fromString(REJECTION_ID),
        new TenantContext(Uuid::fromString(REJECTION_ORG_ID), Uuid::fromString(REJECTION_PROJECT_ID)),
        $reason,
        $detail,
        $payload,
        new DateTimeImmutable('2026-09-22T12:00:00+00:00'),
    );
}
