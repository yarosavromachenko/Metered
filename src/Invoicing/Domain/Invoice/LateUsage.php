<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use Brick\Math\BigDecimal;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * Prices the period at its full current quantity and bills the difference
 * from what was billed (late units alone would miss tier boundaries). A
 * negative difference bills zero; refunds are credit notes (assumption 25).
 */
final class LateUsage
{
    /**
     * @param MeterCharge $billed the period priced at the quantity already billed
     * @param MeterCharge $now    the same period priced at the quantity it holds now
     */
    public static function line(MeterCharge $billed, MeterCharge $now, InvoicePeriod $covers): ?InvoiceLine
    {
        if ($now->quantity->compareTo($billed->quantity) <= 0) {
            return null;
        }

        $owed = $now->amount->minus($billed->amount);

        return InvoiceLine::late(new MeterCharge(
            $now->priceId,
            $now->meterId,
            $now->meterCode,
            Quantity::fromString((string) self::difference($now->quantity, $billed->quantity)),
            $owed->isNegative() ? Money::zero($owed->currency()) : $owed,
            [
                sprintf('%s now holds %s: %s', $covers, $now->quantity, $now->amount),
                ...$now->calculation,
                sprintf('already billed for %s: %s', $billed->quantity, $billed->amount),
            ],
        ), $covers);
    }

    private static function difference(Quantity $now, Quantity $billed): BigDecimal
    {
        return $now->toBigDecimal()->minus($billed->toBigDecimal());
    }
}
