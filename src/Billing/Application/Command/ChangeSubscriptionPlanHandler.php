<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Command;

use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Billing\Domain\Subscription\SubscriptionRepository;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Tenancy\Application\Contract\Authorizer;
use Psr\Clock\ClockInterface;

final readonly class ChangeSubscriptionPlanHandler
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private PlanVersionRepository $versions,
        private Authorizer $authorizer,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(ChangeSubscriptionPlan $command): Subscription
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::ManageCatalog);

        $subscription = $this->subscriptions->find($command->tenant, $command->subscriptionId);

        if (! $subscription instanceof Subscription) {
            throw CatalogNotFound::of('subscription', $command->subscriptionId);
        }

        $version = $this->versions->find($command->tenant, $command->versionId);

        if (! $version instanceof PlanVersion) {
            throw CatalogNotFound::of('plan version', $command->versionId);
        }

        $now = $this->clock->now();
        $changed = $subscription->changePlan($version, $now);
        $this->subscriptions->save($changed);

        $this->audit->record(new AuditEntry(
            organizationId: $command->tenant->organizationId,
            actor: $command->actor->label,
            action: 'subscription.plan_changed',
            subjectType: 'subscription',
            subjectId: $subscription->id->value,
            payload: [
                'plan_version_id' => $version->id->value,
                'effective_at' => $changed->phases[count($changed->phases) - 1]->startsAt->format(DATE_ATOM),
            ],
            occurredAt: $now,
        ));

        return $changed;
    }
}
