<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Metered\Shared\Infrastructure\Tracing\Tracing;
use Metered\Webhooks\Domain\Delivery\Delivery;
use Metered\Webhooks\Domain\Delivery\DeliveryRepository;
use Metered\Webhooks\Domain\Delivery\DeliveryStatus;
use Metered\Webhooks\Domain\Endpoint\EventType;
use stdClass;

final readonly class DatabaseDeliveryRepository implements DeliveryRepository
{
    private const string INSTANT = DatabaseEndpointRepository::INSTANT;

    public function __construct(
        private DatabaseManager $db,
        private Tracing $tracing,
    ) {}

    public function add(Delivery $delivery): bool
    {
        // Trace context stored for the later attempts.
        $trace = $this->tracing->carrier();

        return $this->db->connection()->table('webhook_deliveries')->insertOrIgnore([
            'id' => $delivery->id->value,
            'organization_id' => $delivery->tenant->organizationId->value,
            'project_id' => $delivery->tenant->projectId->value,
            'endpoint_id' => $delivery->endpointId->value,
            'event_id' => $delivery->eventId->value,
            'event_type' => $delivery->eventType->value,
            'body' => $delivery->body,
            'status' => $delivery->status->value,
            'attempts' => $delivery->attempts,
            'next_attempt_at' => $delivery->nextAttemptAt?->format(self::INSTANT),
            'last_status_code' => $delivery->lastStatusCode,
            'created_at' => $delivery->createdAt->format(self::INSTANT),
            'trace_context' => $trace === [] ? null : json_encode($trace, JSON_THROW_ON_ERROR),
        ]) > 0;
    }

    public function save(Delivery $delivery): void
    {
        $this->scoped($delivery->tenant)->where('id', $delivery->id->value)->update([
            'status' => $delivery->status->value,
            'attempts' => $delivery->attempts,
            'next_attempt_at' => $delivery->nextAttemptAt?->format(self::INSTANT),
            'last_status_code' => $delivery->lastStatusCode,
        ]);
    }

    public function find(TenantContext $tenant, Uuid $id): ?Delivery
    {
        $row = $this->scoped($tenant)->where('id', $id->value)->first();

        return $row instanceof stdClass ? $this->toDelivery($row) : null;
    }

    public function findForUpdate(TenantContext $tenant, Uuid $id): ?Delivery
    {
        $row = $this->scoped($tenant)->where('id', $id->value)->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toDelivery($row) : null;
    }

    public function dueAt(DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->db->connection()->table('webhook_deliveries')
            ->where('status', DeliveryStatus::Pending->value)
            ->where('next_attempt_at', '<=', $now->format(self::INSTANT))
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get();

        $due = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $due[] = $this->toDelivery($row);
            }
        }

        return $due;
    }

    private function scoped(TenantContext $tenant): Builder
    {
        return $this->db->connection()->table('webhook_deliveries')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value);
    }

    private function toDelivery(stdClass $row): Delivery
    {
        $values = get_object_vars($row);
        $code = $values['last_status_code'] ?? null;

        return Delivery::restore(
            Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
            new TenantContext(
                Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
                Uuid::fromString(RowReader::string($values['project_id'] ?? null, 'project_id')),
            ),
            Uuid::fromString(RowReader::string($values['endpoint_id'] ?? null, 'endpoint_id')),
            Uuid::fromString(RowReader::string($values['event_id'] ?? null, 'event_id')),
            EventType::from(RowReader::string($values['event_type'] ?? null, 'event_type')),
            RowReader::string($values['body'] ?? null, 'body'),
            DeliveryStatus::from(RowReader::string($values['status'] ?? null, 'status')),
            RowReader::int($values['attempts'] ?? null, 'attempts'),
            RowReader::instantOrNull($values['next_attempt_at'] ?? null, 'next_attempt_at'),
            $code === null ? null : RowReader::int($code, 'last_status_code'),
            RowReader::instant($values['created_at'] ?? null, 'created_at'),
        );
    }
}
