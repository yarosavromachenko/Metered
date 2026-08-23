<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Shared\Domain\Access\Permission;
use Metered\Tenancy\Application\Contract\Authorizer;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Domain\Signing\SecretKey;
use Psr\Clock\ClockInterface;

/**
 * Gives an endpoint a new secret and hands it back, once. The old one keeps
 * signing alongside it for the grace period, so the receiver can switch when
 * it is ready (ADR-0011).
 */
final readonly class RotateEndpointSecretHandler
{
    public function __construct(
        private EndpointRepository $endpoints,
        private Authorizer $authorizer,
        private ClockInterface $clock,
        private EndpointAudit $audit,
        private int $graceSeconds,
    ) {}

    public function handle(RotateEndpointSecret $command): RegisteredEndpoint
    {
        $this->authorizer->ensure($command->actor, $command->tenant->organizationId, Permission::OperateWebhooks);

        $secret = SecretKey::fromBytes(random_bytes(32));
        $endpoint = ($this->endpoints->find($command->tenant, $command->endpointId) ?? throw WebhookNotFound::of('endpoint', $command->endpointId))
            ->rotate($secret, $this->clock->now(), $this->graceSeconds);

        $this->endpoints->save($endpoint);
        $this->audit->record($command->actor, 'webhook_endpoint.secret_rotated', $endpoint);

        return new RegisteredEndpoint($endpoint, $secret);
    }
}
