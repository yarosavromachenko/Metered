<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Billing\Domain\Subscription\SubscriptionRepository;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Outbox\OutboxWriter;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Contract\Authorizer;
use Psr\Clock\ClockInterface;

final readonly class StartSubscriptionHandler
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private CustomerRepository $customers,
        private PlanVersionRepository $versions,
        private Authorizer $authorizer,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AuditLogger $audit,
        private OutboxWriter $outbox,
        private Transactions $transactions,
    ) {}

    public function handle(StartSubscription $command): Subscription
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::ManageCatalog);

        if (! $this->customers->find($command->tenant, $command->customerId) instanceof Customer) {
            throw CatalogNotFound::of('customer', $command->customerId);
        }

        $version = $this->versions->find($command->tenant, $command->versionId);

        if (! $version instanceof PlanVersion) {
            throw CatalogNotFound::of('plan version', $command->versionId);
        }

        $subscription = Subscription::start(
            $this->ids->generate(),
            $command->tenant,
            $command->customerId,
            $version,
            $this->clock->now(),
        );

        // The subscription and the event announcing it commit together
        // (ADR-0005): a webhook about a subscription that rolled back, or a
        // subscription nobody hears about, are both worse than neither.
        $this->transactions->run(function () use ($subscription): void {
            $this->subscriptions->save($subscription);
            $this->outbox->append(SubscriptionMessages::about($this->ids->generate(), $subscription, 'subscription.created', $subscription->anchorAt));
        });

        $this->audit->record(new AuditEntry(
            actor: $command->actor->label,
            action: 'subscription.started',
            subjectType: 'subscription',
            subjectId: $subscription->id->value,
            payload: ['customer_id' => $command->customerId->value, 'plan_version_id' => $version->id->value],
            occurredAt: $subscription->anchorAt,
        ));

        return $subscription;
    }
}
