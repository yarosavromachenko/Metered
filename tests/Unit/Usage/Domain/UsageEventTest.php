<?php

declare(strict_types=1);

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Usage\Domain\EventId;
use Metered\Usage\Domain\Exception\InvalidProperties;
use Metered\Usage\Domain\Properties;
use Metered\Usage\Domain\UsageEvent;

const EVENT_ROW_ID = '01924b7c-0000-7000-8000-000000000101';
const EVENT_ORG_ID = '01924b7c-0000-7000-8000-000000000102';
const EVENT_PROJECT_ID = '01924b7c-0000-7000-8000-000000000103';
const EVENT_CUSTOMER_ID = '01924b7c-0000-7000-8000-000000000104';
const EVENT_METER_ID = '01924b7c-0000-7000-8000-000000000105';

it('is one fact of consumption, and carries where it belongs', function (): void {
    $event = recordEvent();

    expect($event->id->value)->toBe(EVENT_ROW_ID)
        ->and((string) $event->eventId)->toBe('evt_1')
        ->and($event->tenant->projectId->value)->toBe(EVENT_PROJECT_ID)
        ->and($event->customerId->value)->toBe(EVENT_CUSTOMER_ID)
        ->and($event->meterId->value)->toBe(EVENT_METER_ID)
        ->and((string) $event->quantity)->toBe('2.500000')
        ->and($event->occurredAt->format(DATE_ATOM))->toBe('2026-09-22T11:00:00+00:00')
        ->and($event->receivedAt->format(DATE_ATOM))->toBe('2026-09-22T12:00:00+00:00');
});

it('keeps both instants, because the gap between them is the story', function (): void {
    // When it happened is the client's word; when it arrived is ours. A
    // reconciliation or a late-event investigation needs both.
    $event = recordEvent(occurredAt: '2026-09-20T09:00:00+00:00');

    expect($event->occurredAt->format(DATE_ATOM))->toBe('2026-09-20T09:00:00+00:00')
        ->and($event->receivedAt > $event->occurredAt)->toBeTrue();
});

it('holds the instant it was given, whatever offset it wore', function (): void {
    $event = recordEvent(occurredAt: '2026-09-22T14:00:00+03:00');

    expect($event->occurredAt->getTimezone()->getName())->toBe('UTC')
        ->and($event->occurredAt->format(DATE_ATOM))->toBe('2026-09-22T11:00:00+00:00');
});

it('falls in the hour bucket its aggregate is keyed by', function (): void {
    expect(recordEvent(occurredAt: '2026-09-22T11:47:03+00:00')->bucket()->start->format(DATE_ATOM))
        ->toBe('2026-09-22T11:00:00+00:00');
});

it('carries the client’s own labels alongside the number', function (): void {
    $event = recordEvent(properties: ['region' => 'eu-central', 'tier' => 'gold', 'retried' => true, 'attempt' => 2]);

    expect($event->properties->all())
        ->toBe(['region' => 'eu-central', 'tier' => 'gold', 'retried' => true, 'attempt' => 2]);
});

it('accepts an event with no labels at all', function (): void {
    expect(recordEvent()->properties->all())->toBe([])
        ->and(recordEvent()->properties->isEmpty())->toBeTrue();
});

it('refuses labels it could not store or filter on', function (array $properties, string $complaint): void {
    expect(static fn(): Properties => Properties::fromArray($properties))
        ->toThrow(InvalidProperties::class, $complaint);
})->with([
    'a nested structure' => [['tags' => ['a', 'b']], 'not a nested one'],
    'an object' => [['when' => new stdClass()], 'not a nested one'],
    'a key that is not a name' => [[7 => 'seven'], 'named'],
    'an empty key' => [['' => 'nothing'], 'named'],
    'too many labels' => [array_combine(
        array_map(static fn(int $i): string => 'k' . $i, range(1, 33)),
        array_fill(0, 33, 'v'),
    ), 'at most 32'],
]);

it('refuses a label value longer than a label should be', function (): void {
    expect(static fn(): Properties => Properties::fromArray(['note' => str_repeat('n', 257)]))
        ->toThrow(InvalidProperties::class, 'at most 256');
});

/**
 * @param  array<array-key, mixed>  $properties
 */
function recordEvent(
    string $occurredAt = '2026-09-22T11:00:00+00:00',
    array $properties = [],
): UsageEvent {
    return UsageEvent::record(
        Uuid::fromString(EVENT_ROW_ID),
        new TenantContext(Uuid::fromString(EVENT_ORG_ID), Uuid::fromString(EVENT_PROJECT_ID)),
        EventId::fromString('evt_1'),
        Uuid::fromString(EVENT_CUSTOMER_ID),
        Uuid::fromString(EVENT_METER_ID),
        Quantity::fromString('2.5'),
        new DateTimeImmutable($occurredAt),
        new DateTimeImmutable('2026-09-22T12:00:00+00:00'),
        Properties::fromArray($properties),
    );
}

it('takes labels right up to their limits', function (): void {
    $many = [];

    for ($i = 1; $i <= 32; $i++) {
        $many['label_' . $i] = 'x';
    }

    expect(Properties::fromArray($many)->all())->toHaveCount(32)
        ->and(Properties::fromArray(['note' => str_repeat('n', 256)])->all()['note'])->toHaveLength(256);
});

it('refuses a label named only in whitespace', function (): void {
    expect(static fn(): Properties => Properties::fromArray(['   ' => 'eu-central']))
        ->toThrow(InvalidProperties::class, 'Every property has to be named');
});

it('explains why a nested label is refused', function (): void {
    expect(static fn(): Properties => Properties::fromArray(['region' => ['eu', 'central']]))
        ->toThrow(
            InvalidProperties::class,
            'The property "region" has to be a single value, not a nested one. '
            . 'Properties label an event; they do not carry a document.',
        );
});
