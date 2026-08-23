<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Webhooks\Domain\Endpoint\Endpoint;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;
use Metered\Webhooks\Domain\Signing\SecretKey;
use Psr\Clock\ClockInterface;

/**
 * Registers an endpoint and hands back its secret, the one time it is shown.
 */
final readonly class RegisterEndpointHandler
{
    /**
     * @param bool $allowHttp plain http, which only a local environment accepts
     */
    public function __construct(
        private EndpointRepository $endpoints,
        private Authorizer $authorizer,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private EndpointAudit $audit,
        private bool $allowHttp,
    ) {}

    public function handle(RegisterEndpoint $command): RegisteredEndpoint
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::OperateWebhooks);

        $secret = SecretKey::fromBytes(random_bytes(32));
        $endpoint = Endpoint::register(
            $this->ids->generate(),
            $command->tenant,
            EndpointUrl::fromString($command->url, $this->allowHttp),
            $command->description,
            EventTypes::parse($command->eventTypes),
            $secret,
            $this->clock->now(),
        );

        $this->endpoints->save($endpoint);
        $this->audit->record($command->actor, 'webhook_endpoint.registered', $endpoint);

        return new RegisteredEndpoint($endpoint, $secret);
    }
}
