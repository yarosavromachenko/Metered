<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use JsonException;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Domain\Scope;
use RuntimeException;
use stdClass;

final readonly class DatabaseApiKeyRepository implements ApiKeyRepository
{
    public function __construct(private DatabaseManager $db) {}

    public function save(ApiKey $key): void
    {
        $this->db->connection()->table('api_keys')->upsert([
            'id' => $key->id->value,
            'organization_id' => $key->tenant->organizationId->value,
            'project_id' => $key->tenant->projectId->value,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'secret_hash' => $key->secretHash,
            'environment' => $key->environment->value,
            'scopes' => $this->encodeScopes($key->scopes),
            'created_at' => $key->createdAt,
            'revoked_at' => $key->revokedAt,
            'last_used_at' => $key->lastUsedAt,
        ], ['id'], [
            // Prefix, hash and tenant never change; last use is written by recordUse().
            'name',
            'revoked_at',
        ]);
    }

    public function recordUse(ApiKey $key, DateTimeImmutable $at): void
    {
        $instant = $at->format('Y-m-d H:i:s.uP');

        $this->db->connection()->table('api_keys')
            ->where('id', $key->id->value)
            ->where(static fn(Builder $query): Builder => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', $instant))
            ->update(['last_used_at' => $instant]);
    }

    public function findByPrefix(string $prefix): ?ApiKey
    {
        return $this->first(['prefix' => $prefix]);
    }

    public function find(TenantContext $tenant, Uuid $id): ?ApiKey
    {
        return $this->first([
            'id' => $id->value,
            'project_id' => $tenant->projectId->value,
            'organization_id' => $tenant->organizationId->value,
        ]);
    }

    public function listFor(TenantContext $tenant): array
    {
        $rows = $this->db->connection()->table('api_keys')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value)
            ->orderByDesc('created_at')
            ->get();

        $keys = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $keys[] = $this->toApiKey($row);
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, string>  $conditions
     */
    private function first(array $conditions): ?ApiKey
    {
        $row = $this->db->connection()->table('api_keys')->where($conditions)->first();

        return $row instanceof stdClass ? $this->toApiKey($row) : null;
    }

    private function toApiKey(stdClass $row): ApiKey
    {
        $values = get_object_vars($row);

        return ApiKey::fromStorage(
            Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
            new TenantContext(
                Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
                Uuid::fromString(RowReader::string($values['project_id'] ?? null, 'project_id')),
            ),
            RowReader::string($values['name'] ?? null, 'name'),
            RowReader::string($values['prefix'] ?? null, 'prefix'),
            RowReader::string($values['secret_hash'] ?? null, 'secret_hash'),
            Environment::from(RowReader::string($values['environment'] ?? null, 'environment')),
            $this->decodeScopes($values['scopes'] ?? null),
            RowReader::instant($values['created_at'] ?? null, 'created_at'),
            RowReader::instantOrNull($values['revoked_at'] ?? null, 'revoked_at'),
            RowReader::instantOrNull($values['last_used_at'] ?? null, 'last_used_at'),
        );
    }

    /**
     * @param  list<Scope>  $scopes
     */
    private function encodeScopes(array $scopes): string
    {
        try {
            return json_encode(
                array_map(static fn(Scope $scope): string => $scope->value, $scopes),
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Scopes could not be encoded.', $e->getCode(), previous: $e);
        }
    }

    /**
     * @return list<Scope>
     */
    private function decodeScopes(mixed $value): array
    {
        return array_map(
            Scope::from(...),
            RowReader::stringList($value, 'scopes'),
        );
    }
}
