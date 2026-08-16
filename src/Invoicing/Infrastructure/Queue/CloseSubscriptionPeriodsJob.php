<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriods;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriodsHandler;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * One subscription's period close, on the billing queue.
 *
 * WithoutOverlapping keeps two workers from building the same invoice at once
 * and throwing one away. It is an economy, not the guarantee: two closes that
 * slip past it still produce one invoice, because the unique key on
 * (subscription, period) lets only one draft in (ADR-0010).
 */
final class CloseSubscriptionPeriodsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $projectId,
        public readonly string $subscriptionId,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping($this->subscriptionId)->dontRelease()->expireAfter(600)];
    }

    public function handle(CloseSubscriptionPeriodsHandler $handler): void
    {
        $handler->handle(new CloseSubscriptionPeriods(
            new TenantContext(Uuid::fromString($this->organizationId), Uuid::fromString($this->projectId)),
            Uuid::fromString($this->subscriptionId),
            Actor::system('queue:billing:close-periods'),
        ));
    }
}
