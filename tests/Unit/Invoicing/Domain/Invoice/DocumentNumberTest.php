<?php

declare(strict_types=1);

use Metered\Invoicing\Domain\Exception\InvalidInvoice;
use Metered\Invoicing\Domain\Invoice\DocumentNumber;

it('prints each kind with its own prefix, padded to six digits', function (): void {
    expect((string) DocumentNumber::invoice(1))->toBe('INV-000001')
        ->and((string) DocumentNumber::creditNote(42))->toBe('CN-000042')
        ->and((string) DocumentNumber::invoice(1234567))->toBe('INV-1234567')
        ->and(DocumentNumber::invoice(5)->sequence)->toBe(5);
});

it('starts at one', function (int $sequence): void {
    DocumentNumber::invoice($sequence);
})->with([0, -1])->throws(InvalidInvoice::class, 'starts at 1');

it('is equal only to the same number of the same kind', function (): void {
    expect(DocumentNumber::invoice(3)->equals(DocumentNumber::invoice(3)))->toBeTrue()
        ->and(DocumentNumber::invoice(3)->equals(DocumentNumber::invoice(4)))->toBeFalse()
        ->and(DocumentNumber::invoice(3)->equals(DocumentNumber::creditNote(3)))->toBeFalse();
});
