<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceLine;
use Metered\Invoicing\Domain\Invoice\InvoicePeriod;
use Metered\Invoicing\Domain\Invoice\MeterCharge;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Invoices and their parts for domain tests: February 2026 for one customer,
 * a flat fee and a usage line, in EUR.
 */
final class InvoiceFixtures
{
    public static function tenant(): TenantContext
    {
        return new TenantContext(Uuid::fromString('01924b7c-0000-7000-8000-000000000e90'), Uuid::fromString('01924b7c-0000-7000-8000-000000000e91'));
    }

    public static function february(): InvoicePeriod
    {
        return InvoicePeriod::between(new DateTimeImmutable('2026-02-01T00:00:00Z'), new DateTimeImmutable('2026-03-01T00:00:00Z'));
    }

    public static function january(): InvoicePeriod
    {
        return InvoicePeriod::between(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-02-01T00:00:00Z'));
    }

    public static function meterCharge(string $quantity, int $minorUnits, string $currency = 'EUR'): MeterCharge
    {
        return new MeterCharge(
            Uuid::fromString('01924b7c-0000-7000-8000-000000000e81'),
            Uuid::fromString('01924b7c-0000-7000-8000-000000000e82'),
            'api.calls',
            Quantity::fromString($quantity),
            Money::ofMinorUnits($minorUnits, $currency),
            [sprintf('%s × 0.01', $quantity)],
        );
    }

    public static function platformFee(int $minorUnits, string $currency = 'EUR'): InvoiceLine
    {
        return InvoiceLine::fixed(
            Uuid::fromString('01924b7c-0000-7000-8000-000000000e80'),
            'Flat fee',
            Money::ofMinorUnits($minorUnits, $currency),
            self::february(),
            ['flat fee per period'],
        );
    }

    /**
     * @param list<InvoiceLine>|null $lines
     */
    public static function draft(?array $lines = null): Invoice
    {
        return Invoice::draft(
            Uuid::fromString('01924b7c-0000-7000-8000-000000000e70'),
            self::tenant(),
            Uuid::fromString('01924b7c-0000-7000-8000-000000000e71'),
            Uuid::fromString('01924b7c-0000-7000-8000-000000000e72'),
            'EUR',
            self::february(),
            $lines ?? [self::platformFee(2900), InvoiceLine::usage(self::meterCharge('1250', 1250), self::february())],
            new DateTimeImmutable('2026-03-01T01:00:00Z'),
        );
    }
}
