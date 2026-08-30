<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
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
            'demo' => $organization->demo,
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

    public function remove(Uuid $id): void
    {
        $this->db->connection()->table('organizations')->where('id', $id->value)->delete();
    }

    public function demosIdleSince(DateTimeImmutable $cutoff): array
    {
        $instant = $cutoff->format('Y-m-d H:i:s.uP');

        $ids = $this->db->connection()->table('organizations as o')
            ->where('o.demo', true)
            ->where('o.created_at', '<', $instant)
            ->whereNotExists(static function (Builder $members) use ($instant): void {
                $members->selectRaw('1')
                    ->from('organization_members as m')
                    ->join('users as u', 'u.id', '=', 'm.user_id')
                    ->whereColumn('m.organization_id', 'o.id')
                    ->whereRaw('COALESCE(u.last_signed_in_at, u.created_at) >= ?', [$instant]);
            })
            ->orderBy('o.created_at')
            ->pluck('o.id')
            ->all();

        return array_values(array_map(
            static fn(mixed $id): Uuid => Uuid::fromString(RowReader::string($id, 'id')),
            $ids,
        ));
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
            ($values['demo'] ?? false) === true,
        );
    }
}
