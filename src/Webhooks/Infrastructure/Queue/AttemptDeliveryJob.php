<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Webhooks\Application\Delivery\AttemptDelivery;
use Metered\Webhooks\Application\Delivery\AttemptDeliveryHandler;

/**
 * Unique per delivery to avoid piling up copies; duplicates are still
 * harmless thanks to the lease. Not retried by the queue: the delivery has its
 * own retry schedule (config/horizon.php).
 */
final class AttemptDeliveryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor = 60;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $projectId,
        public readonly string $deliveryId,
    ) {}

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    public function handle(AttemptDeliveryHandler $handler): void
    {
        $handler->handle(new AttemptDelivery(
            new TenantContext(Uuid::fromString($this->organizationId), Uuid::fromString($this->projectId)),
            Uuid::fromString($this->deliveryId),
        ));
    }
}
