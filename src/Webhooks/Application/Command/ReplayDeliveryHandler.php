<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Webhooks\Domain\Delivery\Delivery;
use Metered\Webhooks\Domain\Delivery\DeliveryRepository;
use Psr\Clock\ClockInterface;

/**
 * Sends a dead or failed delivery again, from its first attempt, with the
 * same body. It goes out with the next dispatch, seconds later.
 */
final readonly class ReplayDeliveryHandler
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private Transactions $transactions,
        private Authorizer $authorizer,
        private ClockInterface $clock,
        private AuditLogger $audit,
    ) {}

    public function handle(ReplayDelivery $command): Delivery
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::OperateWebhooks);

        $replayed = $this->transactions->run(function () use ($command): Delivery {
            $delivery = $this->deliveries->findForUpdate($command->tenant, $command->deliveryId)
                ?? throw WebhookNotFound::of('delivery', $command->deliveryId);

            $replayed = $delivery->replay($this->clock->now());
            $this->deliveries->save($replayed);

            return $replayed;
        });

        $this->audit->record(new AuditEntry(
            organizationId: $command->tenant->organizationId,
            actor: $command->actor->label,
            action: 'webhook_delivery.replayed',
            subjectType: 'webhook_delivery',
            subjectId: $replayed->id->value,
            payload: ['event_id' => $replayed->eventId->value, 'event_type' => $replayed->eventType->value],
            occurredAt: $this->clock->now(),
        ));

        return $replayed;
    }
}
