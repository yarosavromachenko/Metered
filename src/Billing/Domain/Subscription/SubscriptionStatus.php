<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Subscription;

/**
 * Where a subscription is in its life; the transitions are drawn in
 * docs/domain.md.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case PendingCancellation = 'pending_cancellation';
    case Canceled = 'canceled';
}
