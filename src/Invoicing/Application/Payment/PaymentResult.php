<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Payment;

/**
 * What the provider answered: collected, with its reference, or declined,
 * with its reason.
 */
final readonly class PaymentResult
{
    private function __construct(
        public bool $succeeded,
        public string $reference,
        public ?string $declineReason,
    ) {}

    public static function succeeded(string $reference): self
    {
        return new self(true, $reference, null);
    }

    public static function declined(string $reference, string $reason): self
    {
        return new self(false, $reference, $reason);
    }
}
