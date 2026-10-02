<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface ProjectRepository
{
    public function save(Project $project): void;

    /**
     * By organization and project id, never by id alone.
     */
    public function find(TenantContext $tenant): ?Project;

    public function findBySlug(Uuid $organizationId, Slug $slug): ?Project;

    /**
     * @return list<Project>
     */
    public function listForOrganization(Uuid $organizationId): array;
}
