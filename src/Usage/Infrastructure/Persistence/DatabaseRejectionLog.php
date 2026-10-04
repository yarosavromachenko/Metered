<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Usage\Application\Ingestion\RejectionLog;

/**
 * One insert per batch, outside the event write transaction.
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
                // Truncated to the column size.
                'detail' => mb_substr($rejection->detail, 0, 500),
                'payload' => is_string($payload) ? $payload : '{}',
                'rejected_at' => $rejection->rejectedAt,
            ];
        }

        $this->db->connection($this->connection)->table('usage_event_rejections')->insert($rows);
    }
}
