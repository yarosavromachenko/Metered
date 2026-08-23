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
 * What the four endpoint commands write to the audit log: the endpoint as it
 * now stands, and never its secret — the log is read by everyone who may read
 * the organization.
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
