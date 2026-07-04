<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Usage\Application\Ingestion\RejectionLog;

/**
 * Rejections, written in one statement per batch.
 *
 * Deliberately outside the write transaction. A rejection is a fact about an
 * event that will never be stored, so tying it to the transaction that stores
 * the other events would mean a write failure erases the record of why its
 * neighbours were refused — and then the rejections would be recomputed on
 * redelivery anyway.
 */
final readonly class DatabaseRejectionLog implements RejectionLog
{
    public function __construct(
        private DatabaseManager $db,
        private string $connection,
    ) {}

    public function record(array $rejections): void
    {
        if ($rejections === []) {
            return;
        }

        $rows = [];

        foreach ($rejections as $rejection) {
            $payload = json_encode($rejection->payload);

            $rows[] = [
                'id' => $rejection->id->value,
                'organization_id' => $rejection->tenant->organizationId->value,
                'project_id' => $rejection->tenant->projectId->value,
                'event_id' => $rejection->eventId,
                'reason' => $rejection->reason->value,
                // The column is bounded; a detail that would not fit is
                // truncated rather than costing the row that explains it.
                'detail' => mb_substr($rejection->detail, 0, 500),
                'payload' => is_string($payload) ? $payload : '{}',
                'rejected_at' => $rejection->rejectedAt,
            ];
        }

        $this->db->connection($this->connection)->table('usage_event_rejections')->insert($rows);
    }
}
