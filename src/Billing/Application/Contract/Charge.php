<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * One price's charge for a period, with its calculation. Meter and quantity
 * are null for fixed charges.
 */
final readonly class Charge
{
    /**
     * @param list<string> $calculation
     */
    public function __construct(
        public Uuid $priceId,
        public ?Uuid $meterId,
        public ?string $meterCode,
        public ?Quantity $quantity,
        public Money $amount,
        public array $calculation,
    ) {}
}
