<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * What one metered price charges for one quantity of usage, as the catalog
 * priced it.
 *
 * The invoice does not price anything itself: pricing is Billing's, and a
 * charge arrives here already computed, with the steps that computed it so the
 * line can show its working.
 */
final readonly class MeterCharge
{
    /**
     * @param list<string> $calculation
     */
    public function __construct(
        public Uuid $priceId,
        public Uuid $meterId,
        public string $meterCode,
        public Quantity $quantity,
        public Money $amount,
        public array $calculation,
    ) {}
}
