<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use DateTimeImmutable;
use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Billing\Domain\Subscription\SubscriptionPhase;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Outbox\OutboxMessage;

/**
 * Payloads of the subscription integration events.
 */
final class SubscriptionMessages
{
    public static function about(Uuid $id, Subscription $subscription, string $type, DateTimeImmutable $at): OutboxMessage
    {
        $current = $subscription->phases[count($subscription->phases) - 1];

        return new OutboxMessage(
            $id,
            'subscription',
            $subscription->id,
            $type,
            [
                'subscription_id' => $subscription->id->value,
                'organization_id' => $subscription->tenant->organizationId->value,
                'project_id' => $subscription->tenant->projectId->value,
                'customer_id' => $subscription->customerId->value,
                'status' => $subscription->status->value,
                'plan_version_id' => $current->planVersionId->value,
                'currency' => $subscription->currency,
                'interval' => $subscription->interval->value,
                'anchor_at' => $subscription->anchorAt->format(DATE_ATOM),
                'ends_at' => $subscription->endsAt?->format(DATE_ATOM),
                'phases' => array_map(static fn(SubscriptionPhase $phase): array => [
                    'plan_version_id' => $phase->planVersionId->value,
                    'starts_at' => $phase->startsAt->format(DATE_ATOM),
                    'ends_at' => $phase->endsAt?->format(DATE_ATOM),
                ], $subscription->phases),
            ],
            [],
            $at,
        );
    }
}
