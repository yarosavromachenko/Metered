<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Payment;

/**
 * Where money is actually collected — a payment provider, behind the one call
 * invoicing needs from it.
 *
 * The invoice id is the idempotency key: a provider asked twice to collect for
 * one invoice must collect once. The fake honours that; any real adapter must
 * pass it on (ADR-0008, alternatives).
 */
interface PaymentGateway
{
    public function charge(PaymentRequest $request): PaymentResult;
}
