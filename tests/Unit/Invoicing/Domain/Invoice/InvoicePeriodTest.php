<?php

declare(strict_types=1);

use Metered\Invoicing\Domain\Exception\InvalidInvoice;
use Metered\Invoicing\Domain\Invoice\InvoicePeriod;

it('is a half-open interval, stored in UTC', function (): void {
    $period = InvoicePeriod::between(
        new DateTimeImmutable('2026-02-01T02:00:00+02:00'),
        new DateTimeImmutable('2026-03-01T00:00:00+00:00'),
    );

    expect($period->start->format(DATE_ATOM))->toBe('2026-02-01T00:00:00+00:00')
        ->and($period->end->format(DATE_ATOM))->toBe('2026-03-01T00:00:00+00:00')
        ->and($period->contains(new DateTimeImmutable('2026-02-01T00:00:00+00:00')))->toBeTrue()
        ->and($period->contains(new DateTimeImmutable('2026-02-28T23:59:59.999999+00:00')))->toBeTrue()
        ->and($period->contains(new DateTimeImmutable('2026-03-01T00:00:00+00:00')))->toBeFalse()
        ->and($period->contains(new DateTimeImmutable('2026-01-31T23:59:59.999999+00:00')))->toBeFalse()
        ->and((string) $period)->toBe('2026-02-01 – 2026-03-01');
});

it('refuses a period that does not end after it starts', function (string $end): void {
    InvoicePeriod::between(new DateTimeImmutable('2026-02-01T00:00:00Z'), new DateTimeImmutable($end));
})->with([
    'empty' => '2026-02-01T00:00:00Z',
    'backwards' => '2026-01-31T23:59:59Z',
])->throws(InvalidInvoice::class, 'must end after it starts');

it('closes one grace window after it ends, and not a microsecond before', function (): void {
    $period = InvoicePeriod::between(
        new DateTimeImmutable('2026-01-31T00:00:00Z'),
        new DateTimeImmutable('2026-02-28T00:00:00Z'),
    );

    expect($period->closesAt(3600)->format(DATE_ATOM))->toBe('2026-02-28T01:00:00+00:00')
        ->and($period->isClosableAt(new DateTimeImmutable('2026-02-28T00:59:59.999999Z'), 3600))->toBeFalse()
        ->and($period->isClosableAt(new DateTimeImmutable('2026-02-28T01:00:00Z'), 3600))->toBeTrue()
        ->and($period->isClosableAt(new DateTimeImmutable('2026-02-28T00:00:00Z'), 0))->toBeTrue();
});

it('refuses a negative grace window', function (): void {
    InvoicePeriod::between(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-02-01T00:00:00Z'))
        ->closesAt(-1);
})->throws(InvalidInvoice::class, 'grace window cannot be negative');

it('is equal to another period naming the same instants, to the microsecond', function (): void {
    $period = InvoicePeriod::between(
        new DateTimeImmutable('2026-01-31T10:30:00.250000Z'),
        new DateTimeImmutable('2026-02-28T10:30:00.250000Z'),
    );

    expect($period->equals(InvoicePeriod::between(
        new DateTimeImmutable('2026-01-31T12:30:00.250000+02:00'),
        new DateTimeImmutable('2026-02-28T10:30:00.250000Z'),
    )))->toBeTrue()
        ->and($period->equals(InvoicePeriod::between(
            new DateTimeImmutable('2026-01-31T10:30:00.250001Z'),
            new DateTimeImmutable('2026-02-28T10:30:00.250000Z'),
        )))->toBeFalse()
        ->and($period->equals(InvoicePeriod::between(
            new DateTimeImmutable('2026-01-31T10:30:00.250000Z'),
            new DateTimeImmutable('2026-02-28T10:30:00.250001Z'),
        )))->toBeFalse();
});
