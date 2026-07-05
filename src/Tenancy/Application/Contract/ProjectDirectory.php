<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Contract;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Which projects exist, for the work that runs outside any one tenant.
 *
 * The repository behind Tenancy deliberately refuses to fetch a project by id
 * alone: every request-time lookup names the organization too, so asking for
 * somebody else's project returns nothing. Operator work has the opposite
 * need — `usage:reconcile` across every project, a scheduled sweep, a chaos
 * scenario — and giving it a separate, obviously named door is better than
 * widening the one the request path uses.
 */
interface ProjectDirectory
{
    /**
     * @return list<TenantContext>
     */
    public function all(): array;

    public function find(Uuid $projectId): ?TenantContext;
}
