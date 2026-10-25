<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Endpoint;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Webhooks\Domain\Exception\InvalidEndpoint;

interface EndpointRepository
{
    /**
     * @throws InvalidEndpoint when a new endpoint's project already has as many as it may
     */
    public function save(Endpoint $endpoint): void;

    public function find(TenantContext $tenant, Uuid $id): ?Endpoint;

    /**
     * SELECT ... FOR UPDATE, so only one probe passes the breaker.
     */
    public function findForUpdate(TenantContext $tenant, Uuid $id): ?Endpoint;

    /**
     * @return list<Endpoint>
     */
    public function listeningTo(TenantContext $tenant, EventType $type): array;

    public function remove(TenantContext $tenant, Uuid $id): bool;
}
