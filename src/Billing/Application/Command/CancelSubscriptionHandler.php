<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Billing\Domain\Subscription\SubscriptionRepository;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Tenancy\Application\Contract\Authorizer;
use Psr\Clock\ClockInterface;

final readonly class CancelSubscriptionHandler
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private Authorizer $authorizer,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(CancelSubscription $command): Subscription
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::ManageCatalog);

        $subscription = $this->subscriptions->find($command->tenant, $command->subscriptionId);

        if (! $subscription instanceof Subscription) {
            throw CatalogNotFound::of('subscription', $command->subscriptionId);
        }

        $now = $this->clock->now();
        $canceled = $command->immediately ? $subscription->cancelNow($now) : $subscription->cancelAtPeriodEnd($now);
        $this->subscriptions->save($canceled);

        $this->audit->record(new AuditEntry(
            actor: $command->actor->label,
            action: 'subscription.canceled',
            subjectType: 'subscription',
            subjectId: $subscription->id->value,
            payload: ['immediately' => $command->immediately, 'ends_at' => $canceled->endsAt?->format(DATE_ATOM)],
            occurredAt: $now,
        ));

        return $canceled;
    }
}
