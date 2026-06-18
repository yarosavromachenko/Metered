<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface ProjectRepository
{
    public function save(Project $project): void;

    /**
     * Note the argument: a project is fetched by the pair that identifies it,
     * not by its id alone. Asking for a project id that belongs to another
     * organization returns nothing rather than someone else's project.
     */
    public function find(TenantContext $tenant): ?Project;

    public function findBySlug(Uuid $organizationId, Slug $slug): ?Project;

    /**
     * @return list<Project>
     */
    public function listForOrganization(Uuid $organizationId): array;
}
