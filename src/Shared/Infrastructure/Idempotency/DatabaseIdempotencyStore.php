<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Idempotency;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use JsonException;
use Metered\Shared\Application\Idempotency\IdempotencyStore;
use Metered\Shared\Domain\Idempotency\Claim;
use Metered\Shared\Domain\Idempotency\StoredResponse;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Psr\Clock\ClockInterface;

/**
 * Kept in PostgreSQL, not Redis, which may evict keys under load. The claim
 * is one INSERT ... ON CONFLICT DO NOTHING; no transaction stays open while
 * the request runs.
 */
final readonly class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private DatabaseManager $db,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private int $retentionHours = 24,
    ) {}

    public function claim(string $scope, string $key, string $fingerprint): Claim
    {
        $now = $this->clock->now();

        // Expired but not yet purged keys count as new.
        $this->table()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->where('expires_at', '<', $now)
            ->delete();

        $inserted = $this->table()->insertOrIgnore([
            'id' => $this->ids->generate()->value,
            'scope' => $scope,
            'idempotency_key' => $key,
            'request_fingerprint' => $fingerprint,
            'status' => 'in_progress',
            'created_at' => $now,
            'expires_at' => $now->modify(sprintf('+%d hours', $this->retentionHours)),
        ]);

        if ($inserted === 1) {
            return Claim::claimed();
        }

        $existing = $this->table()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing === null) {
            // Purged between the insert and the read: treat as a new claim.
            return $this->claim($scope, $key, $fingerprint);
        }

        $values = get_object_vars($existing);

        if (RowReader::string($values['request_fingerprint'] ?? null, 'request_fingerprint') !== $fingerprint) {
            return Claim::fingerprintMismatch();
        }

        if (RowReader::string($values['status'] ?? null, 'status') !== 'completed') {
            return Claim::inProgress();
        }

        return Claim::replayed(new StoredResponse(
            status: RowReader::int($values['response_status'] ?? null, 'response_status'),
            headers: RowReader::jsonStringMap($values['response_headers'] ?? '{}', 'response_headers'),
            body: RowReader::string($values['response_body'] ?? '', 'response_body'),
        ));
    }

    public function complete(string $scope, string $key, StoredResponse $response): void
    {
        try {
            $headers = json_encode($response->headers, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $headers = '{}';
        }

        $this->table()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->update([
                'status' => 'completed',
                'response_status' => $response->status,
                'response_headers' => $headers,
                'response_body' => $response->body,
                'completed_at' => $this->clock->now(),
            ]);
    }

    public function release(string $scope, string $key): void
    {
        $this->table()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->where('status', 'in_progress')
            ->delete();
    }

    public function purgeExpired(): int
    {
        return $this->table()->where('expires_at', '<', $this->clock->now())->delete();
    }

    private function table(): Builder
    {
        return $this->db->connection()->table('idempotency_keys');
    }
}
