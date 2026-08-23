<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Persistence;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Metered\Webhooks\Domain\Endpoint\BreakerState;
use Metered\Webhooks\Domain\Endpoint\CircuitBreaker;
use Metered\Webhooks\Domain\Endpoint\Endpoint;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;
use Metered\Webhooks\Domain\Endpoint\EventType;
use Metered\Webhooks\Domain\Signing\SecretKey;
use RuntimeException;
use stdClass;

/**
 * Secrets are encrypted with the application key on the way in and decrypted
 * on the way out; nothing else in the system sees the column.
 */
final readonly class DatabaseEndpointRepository implements EndpointRepository
{
    public const string INSTANT = 'Y-m-d H:i:s.uP';

    public function __construct(
        private DatabaseManager $db,
        private StringEncrypter $encrypter,
    ) {}

    public function save(Endpoint $endpoint): void
    {
        $this->db->connection()->table('webhook_endpoints')->upsert([
            'id' => $endpoint->id->value,
            'organization_id' => $endpoint->tenant->organizationId->value,
            'project_id' => $endpoint->tenant->projectId->value,
            'url' => $endpoint->url->value,
            'description' => $endpoint->description,
            'event_types' => json_encode(array_map(static fn(EventType $t): string => $t->value, $endpoint->eventTypes), JSON_THROW_ON_ERROR),
            'secret' => $this->encrypter->encryptString($endpoint->secret->reveal()),
            'previous_secret' => $endpoint->previousSecret instanceof SecretKey ? $this->encrypter->encryptString($endpoint->previousSecret->reveal()) : null,
            'previous_secret_expires_at' => $endpoint->previousSecretExpiresAt?->format(self::INSTANT),
            'enabled' => $endpoint->enabled,
            'breaker_state' => $endpoint->breaker->state->value,
            'consecutive_failures' => $endpoint->breaker->consecutiveFailures,
            'breaker_changed_at' => $endpoint->breaker->changedAt?->format(self::INSTANT),
            'created_at' => $endpoint->createdAt->format(self::INSTANT),
        ], ['id'], [
            'url', 'description', 'event_types', 'secret', 'previous_secret', 'previous_secret_expires_at',
            'enabled', 'breaker_state', 'consecutive_failures', 'breaker_changed_at',
        ]);
    }

    public function find(TenantContext $tenant, Uuid $id): ?Endpoint
    {
        $row = $this->scoped($tenant)->where('id', $id->value)->first();

        return $row instanceof stdClass ? $this->toEndpoint($row) : null;
    }

    public function findForUpdate(TenantContext $tenant, Uuid $id): ?Endpoint
    {
        $row = $this->scoped($tenant)->where('id', $id->value)->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toEndpoint($row) : null;
    }

    public function listeningTo(TenantContext $tenant, EventType $type): array
    {
        $endpoints = [];

        $rows = $this->scoped($tenant)
            ->where('enabled', true)
            ->whereRaw('event_types @> ?::jsonb', [json_encode([$type->value], JSON_THROW_ON_ERROR)])
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $endpoints[] = $this->toEndpoint($row);
            }
        }

        return $endpoints;
    }

    public function remove(TenantContext $tenant, Uuid $id): bool
    {
        return $this->scoped($tenant)->where('id', $id->value)->delete() > 0;
    }

    private function scoped(TenantContext $tenant): Builder
    {
        return $this->db->connection()->table('webhook_endpoints')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value);
    }

    private function toEndpoint(stdClass $row): Endpoint
    {
        $values = get_object_vars($row);
        $previous = $values['previous_secret'] ?? null;
        $types = array_map(
            EventType::from(...),
            RowReader::stringList($values['event_types'] ?? null, 'event_types'),
        );

        if ($types === []) {
            throw new RuntimeException('A webhook endpoint was stored listening to nothing.');
        }

        return Endpoint::restore(
            Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
            new TenantContext(
                Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
                Uuid::fromString(RowReader::string($values['project_id'] ?? null, 'project_id')),
            ),
            // Stored URLs were accepted when they were written; whether plain
            // http is allowed is a question for the next change, not this read.
            EndpointUrl::fromString(RowReader::string($values['url'] ?? null, 'url'), allowHttp: true),
            RowReader::string($values['description'] ?? null, 'description'),
            $types,
            SecretKey::fromString($this->encrypter->decryptString(RowReader::string($values['secret'] ?? null, 'secret'))),
            $previous === null ? null : SecretKey::fromString($this->encrypter->decryptString(RowReader::string($previous, 'previous_secret'))),
            RowReader::instantOrNull($values['previous_secret_expires_at'] ?? null, 'previous_secret_expires_at'),
            (bool) ($values['enabled'] ?? false),
            CircuitBreaker::restore(
                BreakerState::from(RowReader::string($values['breaker_state'] ?? null, 'breaker_state')),
                RowReader::int($values['consecutive_failures'] ?? null, 'consecutive_failures'),
                RowReader::instantOrNull($values['breaker_changed_at'] ?? null, 'breaker_changed_at'),
            ),
            RowReader::instant($values['created_at'] ?? null, 'created_at'),
        );
    }
}
