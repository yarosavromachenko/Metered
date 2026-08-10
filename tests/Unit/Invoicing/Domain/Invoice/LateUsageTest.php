<?php

declare(strict_types=1);

use Metered\Invoicing\Domain\Invoice\LateUsage;
use Metered\Invoicing\Domain\Invoice\LineKind;
use Tests\Support\InvoiceFixtures;

it('bills the difference between the period priced now and what was billed', function (): void {
    $line = LateUsage::line(InvoiceFixtures::meterCharge('900', 9000), InvoiceFixtures::meterCharge('1100', 10600), InvoiceFixtures::january());

    expect($line?->kind)->toBe(LineKind::Late)
        ->and((string) $line?->quantity)->toBe('200.000000')
        ->and((string) $line?->amount)->toBe('16.00 EUR')
        ->and($line?->covers->equals(InvoiceFixtures::january()))->toBeTrue()
        ->and($line?->meterCode)->toBe('api.calls')
        ->and($line?->priceId->value)->toBe('01924b7c-0000-7000-8000-000000000e81')
        ->and($line?->meterId?->value)->toBe('01924b7c-0000-7000-8000-000000000e82')
        ->and($line?->calculation)->toBe([
            '2026-01-01 – 2026-02-01 now holds 1100.000000: 106.00 EUR',
            '1100 × 0.01',
            'already billed for 900.000000: 90.00 EUR',
        ]);
});

it('bills nothing when no usage arrived since', function (string $now): void {
    expect(LateUsage::line(InvoiceFixtures::meterCharge('900', 9000), InvoiceFixtures::meterCharge($now, 9000), InvoiceFixtures::january()))->toBeNull();
})->with(['the same' => '900', 'less, after a reconcile' => '899.999999']);

it('bills a millionth of a unit that arrived late', function (): void {
    expect((string) LateUsage::line(InvoiceFixtures::meterCharge('900', 9000), InvoiceFixtures::meterCharge('900.000001', 9000), InvoiceFixtures::january())?->quantity)->toBe('0.000001');
});

it('bills zero rather than paying back when more usage costs less', function (): void {
    $line = LateUsage::line(InvoiceFixtures::meterCharge('1000', 10000), InvoiceFixtures::meterCharge('1001', 8008), InvoiceFixtures::january());

    expect((string) $line?->amount)->toBe('0.00 EUR')
        ->and((string) $line?->quantity)->toBe('1.000000');
});
