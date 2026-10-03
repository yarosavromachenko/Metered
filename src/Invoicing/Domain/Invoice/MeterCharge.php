<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * A charge priced by Billing, with its calculation.
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
