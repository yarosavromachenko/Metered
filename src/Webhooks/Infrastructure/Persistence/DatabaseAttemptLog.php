<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Metered\Webhooks\Domain\Delivery\AttemptLog;
use Metered\Webhooks\Domain\Delivery\AttemptResult;
use Metered\Webhooks\Domain\Delivery\Delivery;

final readonly class DatabaseAttemptLog implements AttemptLog
{
    private const string INSTANT = DatabaseEndpointRepository::INSTANT;

    public function __construct(private DatabaseManager $db) {}

    public function record(Delivery $delivery, int $number, DateTimeImmutable $at, AttemptResult $result): void
    {
        $connection = $this->db->connection();

        $connection->table('webhook_attempts')->insert([
            'delivery_id' => $delivery->id->value,
            'organization_id' => $delivery->tenant->organizationId->value,
            'project_id' => $delivery->tenant->projectId->value,
            'number' => $number,
            'attempted_at' => $at->format(self::INSTANT),
            'duration_ms' => $result->durationMs,
            'status_code' => $result->statusCode,
            'error' => $result->error === null ? null : mb_strcut($result->error, 0, 255),
            'response_excerpt' => $result->responseExcerpt,
        ]);

        $connection->table('webhook_deliveries')
            ->where('id', $delivery->id->value)
            ->where('project_id', $delivery->tenant->projectId->value)
            ->update(['last_attempt_at' => $at->format(self::INSTANT)]);
    }
}
