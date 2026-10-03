<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Metered\Webhooks\Domain\Delivery\Delivery;

/**
 * Trace context of each delivery's creation (ADR-0012), kept outside the
 * domain entity. One query per dispatch pass.
 */
final readonly class DeliveryTraceContexts
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * @param  list<Delivery>  $deliveries
     * @return array<string, array<string, string>>  by delivery id; deliveries without a context are absent
     */
    public function of(array $deliveries): array
    {
        if ($deliveries === []) {
            return [];
        }

        $rows = $this->db->connection()->table('webhook_deliveries')
            ->whereIn('project_id', array_values(array_unique(array_map(static fn(Delivery $delivery): string => $delivery->tenant->projectId->value, $deliveries))))
            ->whereIn('id', array_map(static fn(Delivery $delivery): string => $delivery->id->value, $deliveries))
            ->whereNotNull('trace_context')
            ->get(['id', 'trace_context']);

        $contexts = [];

        foreach ($rows as $row) {
            $values = get_object_vars($row);
            $contexts[RowReader::string($values['id'] ?? null, 'id')] = RowReader::jsonStringMap($values['trace_context'] ?? null, 'trace_context');
        }

        return $contexts;
    }
}
