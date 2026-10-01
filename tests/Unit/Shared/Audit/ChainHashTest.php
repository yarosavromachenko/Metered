<?php

declare(strict_types=1);

use Metered\Shared\Domain\Audit\ChainHash;

/**
 * @param  array<string, mixed>  $overrides
 */
function hashFor(array $overrides = []): string
{
    $defaults = [
        'previousHash' => ChainHash::GENESIS,
        'actor' => 'user:01932b1c',
        'action' => 'invoice.voided',
        'subjectType' => 'invoice',
        'subjectId' => 'inv-117',
        'payload' => ['reason' => 'duplicate'],
        'occurredAt' => new DateTimeImmutable('2026-09-10T09:00:00.123456+00:00'),
        'organizationId' => null,
    ];

    /** @var array{previousHash: string, actor: string, action: string, subjectType: string, subjectId: ?string, payload: array<string, mixed>, occurredAt: DateTimeImmutable, organizationId: ?string} $args */
    $args = [...$defaults, ...$overrides];

    return ChainHash::compute(
        $args['previousHash'],
        $args['actor'],
        $args['action'],
        $args['subjectType'],
        $args['subjectId'],
        $args['payload'],
        $args['occurredAt'],
        $args['organizationId'],
    );
}

it('produces the same hash for the same entry', function (): void {
    expect(hashFor())->toBe(hashFor());
});

it('changes when any part of the entry changes', function (string $field, mixed $value): void {
    expect(hashFor([$field => $value]))->not->toBe(hashFor());
})->with([
    'the predecessor' => ['previousHash', 'a' . str_repeat('0', 63)],
    'the actor' => ['actor', 'user:someone-else'],
    'the action' => ['action', 'invoice.paid'],
    'the subject type' => ['subjectType', 'payment'],
    'the subject id' => ['subjectId', 'inv-118'],
    'the payload' => ['payload', ['reason' => 'fraud']],
    'the instant' => ['occurredAt', new DateTimeImmutable('2026-09-10T09:00:00.123457+00:00')],
    'the organization' => ['organizationId', '01a0f4ad-54b4-701b-90f7-24a8c2eff77c'],
]);

it('tells one organization’s chain from another’s', function (): void {
    // An entry cannot be moved into another chain and still verify.
    expect(hashFor(['organizationId' => '01a0f4ad-54b4-701b-90f7-24a8c2eff77c']))
        ->not->toBe(hashFor(['organizationId' => '01a0f4b0-8571-7068-9094-2817f79fd199']));
});

it('distinguishes a null subject from an empty one', function (): void {
    expect(hashFor(['subjectId' => null]))->not->toBe(hashFor(['subjectId' => '']));
});

it('does not depend on the order the payload keys were written in', function (): void {
    // Two callers recording the same facts must produce the same link,
    // whichever order they happened to build the array in.
    $one = hashFor(['payload' => ['reason' => 'duplicate', 'amount' => 1999]]);
    $other = hashFor(['payload' => ['amount' => 1999, 'reason' => 'duplicate']]);

    expect($one)->toBe($other);
});

it('does not depend on how the instant was expressed', function (): void {
    $utc = hashFor(['occurredAt' => new DateTimeImmutable('2026-09-10T09:00:00.123456+00:00')]);
    $elsewhere = hashFor(['occurredAt' => new DateTimeImmutable('2026-09-10T11:00:00.123456+02:00')]);

    expect($utc)->toBe($elsewhere);
});

it('is a sha-256 digest', function (): void {
    expect(hashFor())->toMatch('/^[0-9a-f]{64}$/')
        ->and(ChainHash::GENESIS)->toMatch('/^0{64}$/');
});

it('pins the encoding with a known answer', function (): void {
    // A stored chain outlives the code that wrote it. If the encoding ever
    // changes — a different JSON flag, a different timestamp format — every
    // existing entry stops verifying, and the failure looks like tampering.
    //
    // This vector is what makes that impossible to do by accident. The payload
    // carries a slash and a non-ASCII character on purpose: both are escaped by
    // PHP's default flags and deliberately are not here.
    $hash = ChainHash::compute(
        ChainHash::GENESIS,
        'user:01932b1c',
        'invoice.voided',
        'invoice',
        'inv-117',
        ['reason' => 'duplicate/refund', 'note' => 'café'],
        new DateTimeImmutable('2026-09-10T09:00:00.123456+00:00'),
        null,
    );

    // Entries written before chains were kept per organization carry none and
    // hash exactly as they did: this vector is the one from before.
    expect($hash)->toBe('d9aaee37d6d143f5ab4d16281834674ebf243d8bd7815755999bdefa8053f5f3');
});

it('pins where the organization sits in the encoding', function (): void {
    // The same entry in an organization's chain: the organization is the last
    // field of the canonical JSON, which is what a hand-made sha256 of
    // {"prev":…,"occurred_at":"…","organization_id":"01a0f4ad-…"} gives.
    $hash = ChainHash::compute(
        ChainHash::GENESIS,
        'user:01932b1c',
        'invoice.voided',
        'invoice',
        'inv-117',
        ['reason' => 'duplicate/refund', 'note' => 'café'],
        new DateTimeImmutable('2026-09-10T09:00:00.123456+00:00'),
        '01a0f4ad-54b4-701b-90f7-24a8c2eff77c',
    );

    expect($hash)->toBe('61d079d95a43f2928d004ab81bdf02770b97c258e5ec2e511db959af7fcff382');
});
