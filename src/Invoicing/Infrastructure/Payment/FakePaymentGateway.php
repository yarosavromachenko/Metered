<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Payment;

use Metered\Invoicing\Application\Payment\PaymentGateway;
use Metered\Invoicing\Application\Payment\PaymentRequest;
use Metered\Invoicing\Application\Payment\PaymentResult;

/**
 * Collects every payment, at once, and pretends nothing more.
 *
 * There is no real provider in this system (README, what is not here); this
 * stands where one would. The reference is derived from the invoice, so the
 * same invoice always gets the same one — which is what idempotency at a real
 * provider looks like from this side.
 */
final readonly class FakePaymentGateway implements PaymentGateway
{
    public function charge(PaymentRequest $request): PaymentResult
    {
        return PaymentResult::succeeded('fake_' . substr(hash('sha256', $request->invoiceId->value), 0, 24));
    }
}
