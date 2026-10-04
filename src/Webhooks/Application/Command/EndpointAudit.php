<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Webhooks\Domain\Endpoint\Endpoint;
use Metered\Webhooks\Domain\Endpoint\EventType;
use Psr\Clock\ClockInterface;

/**
 * Audit payload of the endpoint commands; never includes the secret.
 */
final readonly class EndpointAudit
{
    public function __construct(
        private AuditLogger $audit,
        private ClockInterface $clock,
    ) {}

    public function record(Actor $actor, string $action, Endpoint $endpoint): void
    {
        $this->audit->record(new AuditEntry(
            organizationId: $endpoint->tenant->organizationId,
            actor: $actor->label,
            action: $action,
            subjectType: 'webhook_endpoint',
            subjectId: $endpoint->id->value,
            payload: [
                'url' => $endpoint->url->value,
                'events' => array_map(static fn(EventType $t): string => $t->value, $endpoint->eventTypes),
                'enabled' => $endpoint->enabled,
                'secret' => $endpoint->secret->masked(),
            ],
            occurredAt: $this->clock->now(),
        ));
    }
}
