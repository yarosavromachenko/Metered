<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Outbox\RowReader;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\ProjectRepository;
use Metered\Tenancy\Domain\Slug;
use stdClass;

final readonly class DatabaseProjectRepository implements ProjectRepository
{
    public function __construct(private DatabaseManager $db) {}

    public function save(Project $project): void
    {
        $this->db->connection()->table('projects')->upsert([
            'id' => $project->id->value,
            'organization_id' => $project->organizationId->value,
            'name' => $project->name,
            'slug' => $project->slug->value,
            'environment' => $project->environment->value,
            'currency' => $project->currency,
            'created_at' => $project->createdAt,
        ], ['id'], ['name', 'slug']);
    }

    public function find(TenantContext $tenant): ?Project
    {
        return $this->first([
            'id' => $tenant->projectId->value,
            'organization_id' => $tenant->organizationId->value,
        ]);
    }

    public function findBySlug(Uuid $organizationId, Slug $slug): ?Project
    {
        return $this->first([
            'organization_id' => $organizationId->value,
            'slug' => $slug->value,
        ]);
    }

    public function listForOrganization(Uuid $organizationId): array
    {
        $rows = $this->db->connection()->table('projects')
            ->where('organization_id', $organizationId->value)
            ->orderBy('created_at')
            ->get();

        $projects = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $projects[] = $this->toProject($row);
            }
        }

        return $projects;
    }

    /**
     * @param  array<string, string>  $conditions
     */
    private function first(array $conditions): ?Project
    {
        $row = $this->db->connection()->table('projects')->where($conditions)->first();

        return $row instanceof stdClass ? $this->toProject($row) : null;
    }

    private function toProject(stdClass $row): Project
    {
        $values = get_object_vars($row);

        return Project::open(
            Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
            Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
            RowReader::string($values['name'] ?? null, 'name'),
            Slug::fromString(RowReader::string($values['slug'] ?? null, 'slug')),
            Environment::from(RowReader::string($values['environment'] ?? null, 'environment')),
            RowReader::string($values['currency'] ?? null, 'currency'),
            TenancyRow::instant($values['created_at'] ?? null, 'created_at'),
        );
    }
}
