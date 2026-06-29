<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Metered\Tenancy\Domain\Organization;
use Metered\Tenancy\Domain\OrganizationRepository;
use Metered\Tenancy\Domain\Slug;
use stdClass;

final readonly class DatabaseOrganizationRepository implements OrganizationRepository
{
    public function __construct(private DatabaseManager $db) {}

    public function save(Organization $organization): void
    {
        $this->db->connection()->table('organizations')->upsert([
            'id' => $organization->id->value,
            'name' => $organization->name,
            'slug' => $organization->slug->value,
            'created_at' => $organization->createdAt,
        ], ['id'], ['name', 'slug']);
    }

    public function find(Uuid $id): ?Organization
    {
        return $this->first(['id' => $id->value]);
    }

    public function findBySlug(Slug $slug): ?Organization
    {
        return $this->first(['slug' => $slug->value]);
    }

    /**
     * @param  array<string, string>  $conditions
     */
    private function first(array $conditions): ?Organization
    {
        $row = $this->db->connection()->table('organizations')->where($conditions)->first();

        return $row instanceof stdClass ? $this->toOrganization($row) : null;
    }

    private function toOrganization(stdClass $row): Organization
    {
        $values = get_object_vars($row);

        return Organization::register(
            Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
            RowReader::string($values['name'] ?? null, 'name'),
            Slug::fromString(RowReader::string($values['slug'] ?? null, 'slug')),
            RowReader::instant($values['created_at'] ?? null, 'created_at'),
        );
    }
}
