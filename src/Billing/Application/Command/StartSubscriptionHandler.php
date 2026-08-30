<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use DateInterval;
use DateTimeImmutable;
use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Billing\Domain\Exception\SubscriptionChangeRefused;
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
    /**
     * How far back a subscription may start. A year covers moving a customer
     * over from another system with their anchor intact; anything older would
     * invoice history nobody can check any more in one period close.
     */
    public const int MAX_BACKDATE_DAYS = 366;

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

        $now = $this->clock->now();

        $subscription = Subscription::start(
            $this->ids->generate(),
            $command->tenant,
            $command->customerId,
            $version,
            $this->startAt($command->startsAt, $now),
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
            payload: [
                'customer_id' => $command->customerId->value,
                'plan_version_id' => $version->id->value,
                'anchor_at' => $subscription->anchorAt->format(DATE_ATOM),
            ],
            // When it was done, which for a backdated start is not the anchor.
            occurredAt: $now,
        ));

        return $subscription;
    }

    private function startAt(?DateTimeImmutable $requested, DateTimeImmutable $now): DateTimeImmutable
    {
        if (! $requested instanceof DateTimeImmutable) {
            return $now;
        }

        if ($requested > $now) {
            throw SubscriptionChangeRefused::startsInTheFuture();
        }

        if ($requested < $now->sub(new DateInterval(sprintf('P%dD', self::MAX_BACKDATE_DAYS)))) {
            throw SubscriptionChangeRefused::backdatedTooFar(self::MAX_BACKDATE_DAYS);
        }

        return $requested;
    }
}
