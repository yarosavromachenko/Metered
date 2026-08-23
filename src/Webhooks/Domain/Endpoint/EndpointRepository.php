<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Endpoint;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface EndpointRepository
{
    public function save(Endpoint $endpoint): void;

    public function find(TenantContext $tenant, Uuid $id): ?Endpoint;

    /**
     * As find(), holding the row until the transaction ends: the breaker is
     * read and moved by every delivery to the endpoint, and two of them must
     * not both let a probe through.
     */
    public function findForUpdate(TenantContext $tenant, Uuid $id): ?Endpoint;

    /**
     * The enabled endpoints of the project that listen to $type.
     *
     * @return list<Endpoint>
     */
    public function listeningTo(TenantContext $tenant, EventType $type): array;

    public function remove(TenantContext $tenant, Uuid $id): bool;
}
