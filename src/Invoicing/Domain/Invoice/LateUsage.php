<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use Brick\Math\BigDecimal;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * What an earlier period still owes for usage that reached it after its
 * invoice was built.
 *
 * The period is priced again at everything it now holds, and the late line
 * bills the difference from what was already billed. Pricing the late units
 * on their own would be wrong under tiers: 200 late units after 900 billed
 * belong partly to the second tier, which only the whole quantity reveals.
 *
 * A volume discount can make more usage cost less. The late line then bills
 * nothing rather than paying money back: a refund is a credit note, a
 * document someone decides to issue, not a side effect of an event arriving
 * late (assumptions, 25).
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
