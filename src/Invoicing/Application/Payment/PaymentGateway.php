<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Payment;

/**
 * The invoice id is the idempotency key; a real adapter must pass it to the
 * provider (ADR-0008, alternatives).
 */
interface PaymentGateway
{
    public function charge(PaymentRequest $request): PaymentResult;
}
