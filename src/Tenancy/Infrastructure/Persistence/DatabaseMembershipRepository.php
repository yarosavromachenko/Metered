<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Metered\Tenancy\Domain\Membership;
use Metered\Tenancy\Domain\MembershipRepository;
use Metered\Tenancy\Domain\Role;
use stdClass;

final readonly class DatabaseMembershipRepository implements MembershipRepository
{
    public function __construct(private DatabaseManager $db) {}

    public function save(Membership $membership): void
    {
        $this->db->connection()->table('organization_members')->upsert([
            'id' => $membership->id->value,
            'organization_id' => $membership->organizationId->value,
            'user_id' => $membership->userId->value,
            'role' => $membership->role->value,
            'created_at' => $membership->createdAt,
        ], ['organization_id', 'user_id'], ['role']);
    }

    public function find(Uuid $organizationId, Uuid $userId): ?Membership
    {
        $row = $this->db->connection()->table('organization_members')
            ->where('organization_id', $organizationId->value)
            ->where('user_id', $userId->value)
            ->first();

        return $row instanceof stdClass ? $this->toMembership($row) : null;
    }

    public function forUser(Uuid $userId): array
    {
        return $this->list('user_id', $userId);
    }

    public function forOrganization(Uuid $organizationId): array
    {
        return $this->list('organization_id', $organizationId);
    }

    /**
     * @return list<Membership>
     */
    private function list(string $column, Uuid $id): array
    {
        $rows = $this->db->connection()->table('organization_members')
            ->where($column, $id->value)
            ->orderBy('created_at')
            ->get();

        $memberships = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $memberships[] = $this->toMembership($row);
            }
        }

        return $memberships;
    }

    private function toMembership(stdClass $row): Membership
    {
        $values = get_object_vars($row);

        return new Membership(
            Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
            Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
            Uuid::fromString(RowReader::string($values['user_id'] ?? null, 'user_id')),
            Role::from(RowReader::string($values['role'] ?? null, 'role')),
            RowReader::instant($values['created_at'] ?? null, 'created_at'),
        );
    }
}
