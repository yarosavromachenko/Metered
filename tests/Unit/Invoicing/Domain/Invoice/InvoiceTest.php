<?php

declare(strict_types=1);

use Metered\Invoicing\Domain\Exception\InvalidInvoice;
use Metered\Invoicing\Domain\Exception\InvoiceTransitionRefused;
use Metered\Invoicing\Domain\Invoice\DocumentNumber;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceLine;
use Metered\Invoicing\Domain\Invoice\InvoicePeriod;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
use Metered\Invoicing\Domain\Invoice\LineKind;
use Metered\Shared\Domain\Exception\CurrencyMismatch;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Tests\Support\InvoiceFixtures;

it('is built as a draft, without a number, totalling its lines', function (): void {
    $invoice = InvoiceFixtures::draft();

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->number)->toBeNull()
        ->and($invoice->finalizedAt)->toBeNull()
        ->and((string) $invoice->total())->toBe('41.50 EUR')
        ->and($invoice->builtAt->format(DATE_ATOM))->toBe('2026-03-01T01:00:00+00:00')
        ->and($invoice->billTo->reference)->toBe('cus_4471')
        ->and($invoice->billTo->name)->toBe('North Wind Ltd')
        ->and($invoice->lines[1]->kind)->toBe(LineKind::Usage)
        ->and($invoice->lines[1]->description)->toBe('Usage of api.calls')
        ->and((string) $invoice->lines[1]->quantity)->toBe('1250.000000')
        ->and($invoice->lines[1]->calculation)->toBe(['1250 × 0.01']);
});

it('totals nothing to zero in its own currency', function (): void {
    expect((string) InvoiceFixtures::draft([])->total())->toBe('0.00 EUR');
});

it('refuses a line in another currency', function (): void {
    InvoiceFixtures::draft([InvoiceFixtures::platformFee(100), InvoiceFixtures::platformFee(100, 'USD')]);
})->throws(InvalidInvoice::class, 'An invoice in EUR cannot carry a line in USD.');

it('refuses a late line that bills its own period or a later one', function (InvoicePeriod $covers): void {
    InvoiceFixtures::draft([InvoiceLine::late(InvoiceFixtures::meterCharge('10', 10), $covers)]);
})->with([
    'its own' => [InvoiceFixtures::february()],
    'overlapping it' => [InvoicePeriod::between(new DateTimeImmutable('2026-01-15T00:00:00Z'), new DateTimeImmutable('2026-02-01T00:00:01Z'))],
])->throws(InvalidInvoice::class, 'late line bills an earlier period');

it('takes a late line for the period just before it', function (): void {
    $invoice = InvoiceFixtures::draft([InvoiceLine::late(InvoiceFixtures::meterCharge('10', 10), InvoiceFixtures::january())]);

    expect($invoice->lines[0]->kind)->toBe(LineKind::Late)
        ->and($invoice->lines[0]->description)->toBe('Late usage of api.calls for 2026-01-01 – 2026-02-01');
});

it('refuses a line charging a negative amount', function (): void {
    InvoiceLine::fixed(Uuid::fromString('01924b7c-0000-7000-8000-000000000e80'), 'Refund', Money::ofMinorUnits(-1, 'EUR'), InvoiceFixtures::february(), []);
})->throws(InvalidInvoice::class, 'cannot charge a negative amount');

it('takes a line charging nothing', function (): void {
    expect(InvoiceFixtures::platformFee(0)->amount->isZero())->toBeTrue();
});

it('is numbered and fixed when finalized', function (): void {
    $at = new DateTimeImmutable('2026-03-01T01:00:05Z');
    $invoice = InvoiceFixtures::draft()->finalize(DocumentNumber::invoice(42), $at);

    expect($invoice->status)->toBe(InvoiceStatus::Finalized)
        ->and((string) $invoice->number)->toBe('INV-000042')
        ->and($invoice->finalizedAt)->toBe($at)
        ->and($invoice->paidAt)->toBeNull()
        ->and($invoice->voidedAt)->toBeNull()
        ->and((string) $invoice->total())->toBe('41.50 EUR');
});

it('is settled at once when it is finalized for nothing', function (): void {
    $at = new DateTimeImmutable('2026-03-01T01:00:05Z');
    $invoice = InvoiceFixtures::draft([InvoiceFixtures::platformFee(0)])->finalize(DocumentNumber::invoice(1), $at);

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->finalizedAt)->toBe($at)
        ->and($invoice->paidAt)->toBe($at);
});

it('is paid once finalized', function (): void {
    $at = new DateTimeImmutable('2026-03-05T09:00:00Z');
    $invoice = InvoiceFixtures::draft()->finalize(DocumentNumber::invoice(1), new DateTimeImmutable('2026-03-01T01:00:05Z'))->pay($at);

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->paidAt)->toBe($at)
        ->and((string) $invoice->number)->toBe('INV-000001')
        ->and($invoice->finalizedAt?->format(DATE_ATOM))->toBe('2026-03-01T01:00:05+00:00');
});

it('is voided once finalized, keeping its number', function (): void {
    $at = new DateTimeImmutable('2026-03-05T09:00:00Z');
    $invoice = InvoiceFixtures::draft()->finalize(DocumentNumber::invoice(7), new DateTimeImmutable('2026-03-01T01:00:05Z'))->void($at);

    expect($invoice->status)->toBe(InvoiceStatus::Void)
        ->and($invoice->voidedAt)->toBe($at)
        ->and($invoice->paidAt)->toBeNull()
        ->and((string) $invoice->number)->toBe('INV-000007')
        ->and($invoice->finalizedAt?->format(DATE_ATOM))->toBe('2026-03-01T01:00:05+00:00');
});

it('is discarded as a draft, without ever being numbered', function (): void {
    $at = new DateTimeImmutable('2026-03-01T02:00:00Z');
    $invoice = InvoiceFixtures::draft()->discard($at);

    expect($invoice->status)->toBe(InvoiceStatus::Void)
        ->and($invoice->number)->toBeNull()
        ->and($invoice->finalizedAt)->toBeNull()
        ->and($invoice->voidedAt)->toBe($at);
});

it('refuses every move its state does not allow', function (Closure $move, string $message): void {
    /** @var Closure(Invoice): Invoice $move */
    expect(static fn(): Invoice => $move(InvoiceFixtures::draft()))->toThrow(InvoiceTransitionRefused::class, $message);
})->with([
    'finalize twice' => [fn(Invoice $i): Invoice => $i->finalize(DocumentNumber::invoice(1), new DateTimeImmutable())->finalize(DocumentNumber::invoice(2), new DateTimeImmutable()), 'A finalized invoice cannot be finalized.'],
    'pay a draft' => [fn(Invoice $i): Invoice => $i->pay(new DateTimeImmutable()), 'A draft invoice cannot be paid.'],
    'void a draft' => [fn(Invoice $i): Invoice => $i->void(new DateTimeImmutable()), 'A draft invoice cannot be voided.'],
    'discard a finalized' => [fn(Invoice $i): Invoice => $i->finalize(DocumentNumber::invoice(1), new DateTimeImmutable())->discard(new DateTimeImmutable()), 'A finalized invoice cannot be discarded.'],
    'pay twice' => [fn(Invoice $i): Invoice => $i->finalize(DocumentNumber::invoice(1), new DateTimeImmutable())->pay(new DateTimeImmutable())->pay(new DateTimeImmutable()), 'A paid invoice cannot be paid.'],
    'void a paid' => [fn(Invoice $i): Invoice => $i->finalize(DocumentNumber::invoice(1), new DateTimeImmutable())->pay(new DateTimeImmutable())->void(new DateTimeImmutable()), 'A paid invoice cannot be voided.'],
    'finalize a void' => [fn(Invoice $i): Invoice => $i->discard(new DateTimeImmutable())->finalize(DocumentNumber::invoice(1), new DateTimeImmutable()), 'A void invoice cannot be finalized.'],
]);

it('restores exactly what was stored', function (): void {
    $finalized = InvoiceFixtures::draft()->finalize(DocumentNumber::invoice(3), new DateTimeImmutable('2026-03-01T01:00:05Z'))
        ->pay(new DateTimeImmutable('2026-03-02T00:00:00Z'));

    $restored = Invoice::restore(
        $finalized->id,
        $finalized->tenant,
        $finalized->customerId,
        $finalized->billTo,
        $finalized->subscriptionId,
        $finalized->currency,
        $finalized->period,
        $finalized->lines,
        $finalized->status,
        $finalized->number,
        $finalized->builtAt,
        $finalized->finalizedAt,
        $finalized->paidAt,
        $finalized->voidedAt,
    );

    expect($restored)->toEqual($finalized);
});

it('refuses to add lines of two currencies even through the total', function (): void {
    Money::ofMinorUnits(1, 'EUR')->plus(Money::ofMinorUnits(1, 'USD'));
})->throws(CurrencyMismatch::class);
