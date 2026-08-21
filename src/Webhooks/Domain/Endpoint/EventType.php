<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Endpoint;

/**
 * What an endpoint can subscribe to — the integration events the system
 * publishes that a tenant's own systems would act on.
 */
enum EventType: string
{
    case SubscriptionCreated = 'subscription.created';
    case SubscriptionCanceled = 'subscription.canceled';
    case InvoiceFinalized = 'invoice.finalized';
    case InvoicePaid = 'invoice.paid';
    case InvoiceVoided = 'invoice.voided';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn(self $type): string => $type->value, self::cases());
    }
}
