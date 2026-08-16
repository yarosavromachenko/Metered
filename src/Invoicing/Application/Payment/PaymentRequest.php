<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Payment;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;

final readonly class PaymentRequest
{
    public function __construct(
        public Uuid $invoiceId,
        public Uuid $customerId,
        public Money $amount,
        public string $description,
    ) {}
}
