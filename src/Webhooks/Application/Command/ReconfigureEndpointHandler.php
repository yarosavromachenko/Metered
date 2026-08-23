<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Shared\Domain\Access\Permission;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Webhooks\Domain\Endpoint\Endpoint;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;

/**
 * Points an endpoint elsewhere, changes what it listens to, or switches it
 * on and off. Its secret is untouched.
 */
final readonly class ReconfigureEndpointHandler
{
    public function __construct(
        private EndpointRepository $endpoints,
        private Authorizer $authorizer,
        private EndpointAudit $audit,
        private bool $allowHttp,
    ) {}

    public function handle(ReconfigureEndpoint $command): Endpoint
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::OperateWebhooks);

        $endpoint = ($this->endpoints->find($command->tenant, $command->endpointId) ?? throw WebhookNotFound::of('endpoint', $command->endpointId))
            ->reconfigure(
                EndpointUrl::fromString($command->url, $this->allowHttp),
                $command->description,
                EventTypes::parse($command->eventTypes),
                $command->enabled,
            );

        $this->endpoints->save($endpoint);
        $this->audit->record($command->actor, 'webhook_endpoint.reconfigured', $endpoint);

        return $endpoint;
    }
}
