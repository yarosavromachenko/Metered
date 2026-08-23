<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Shared\Domain\Access\Permission;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;

/**
 * Removes an endpoint, and with it its deliveries and their log. Switching it
 * off keeps both.
 */
final readonly class RemoveEndpointHandler
{
    public function __construct(
        private EndpointRepository $endpoints,
        private Authorizer $authorizer,
        private EndpointAudit $audit,
    ) {}

    public function handle(RemoveEndpoint $command): void
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::OperateWebhooks);

        $endpoint = $this->endpoints->find($command->tenant, $command->endpointId) ?? throw WebhookNotFound::of('endpoint', $command->endpointId);

        $this->endpoints->remove($command->tenant, $endpoint->id);
        $this->audit->record($command->actor, 'webhook_endpoint.removed', $endpoint);
    }
}
