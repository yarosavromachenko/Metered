<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Metered\Webhooks\Domain\Delivery\Delivery;

/**
 * The trace context each delivery was created in, for the pass that queues
 * its attempts (ADR-0012).
 *
 * Kept out of the domain's Delivery: a trace is how the system is observed,
 * not something a delivery is. Read in one query per pass, for the deliveries
 * that pass already holds and within their projects.
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
