<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Quantity\Quantity;

/**
 * What one price of a plan version charges for a period, with the working.
 *
 * A fixed charge has no meter and no quantity; a metered one has both, and the
 * quantity is the usage it was priced at.
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
