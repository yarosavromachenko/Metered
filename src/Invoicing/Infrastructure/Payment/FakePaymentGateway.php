<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Payment;

use Metered\Invoicing\Application\Payment\PaymentGateway;
use Metered\Invoicing\Application\Payment\PaymentRequest;
use Metered\Invoicing\Application\Payment\PaymentResult;

/**
 * Always succeeds; there is no real provider (README). The reference is
 * derived from the invoice id, so it is idempotent like a real provider.
 */
final readonly class FakePaymentGateway implements PaymentGateway
{
    public function charge(PaymentRequest $request): PaymentResult
    {
        return PaymentResult::succeeded('fake_' . substr(hash('sha256', $request->invoiceId->value), 0, 24));
    }
}
