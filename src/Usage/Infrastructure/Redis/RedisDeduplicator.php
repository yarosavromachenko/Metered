<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Usage\Application\Ingestion\Deduplicator;
use Metered\Usage\Domain\EventId;
use Redis;

/**
 * `SET key NX EX ttl`, once per event, pipelined.
 *
 * `NX` is what makes this a claim rather than a lookup: the answer and the
 * reservation are one operation, so two consumers handed the same redelivered
 * batch cannot both conclude they were first. A GET followed by a SET would
 * be a race with a customer's invoice as the stake.
 *
 * The keys are namespaced per project, because an event id is the client's
 * own string and two tenants may pick the same one.
 */
final readonly class RedisDeduplicator implements Deduplicator
{
    public function __construct(
        private PhpRedisConnection $connection,
        private int $ttlSeconds,
    ) {}

    public function claim(TenantContext $tenant, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $keys = array_map(fn(EventId $id): string => $this->key($tenant, $id), $eventIds);
        $ttl = $this->ttlSeconds;

        /** @var list<mixed> $answers */
        $answers = $this->connection->pipeline(static function (Redis $pipe) use ($keys, $ttl): void {
            foreach ($keys as $key) {
                $pipe->set($key, '1', ['nx', 'ex' => $ttl]);
            }
        });

        $claimed = [];

        foreach ($eventIds as $index => $eventId) {
            // Redis answers false for a key that was already there, which is
            // precisely the duplicate case.
            if (($answers[$index] ?? false) !== false) {
                $claimed[] = $eventId;
            }
        }

        return $claimed;
    }

    public function release(TenantContext $tenant, array $eventIds): void
    {
        if ($eventIds === []) {
            return;
        }

        $keys = array_map(fn(EventId $id): string => $this->key($tenant, $id), $eventIds);

        $this->connection->pipeline(static function (Redis $pipe) use ($keys): void {
            foreach ($keys as $key) {
                $pipe->del($key);
            }
        });
    }

    private function key(TenantContext $tenant, EventId $eventId): string
    {
        return 'usage:dedup:' . $tenant->projectId->value . ':' . $eventId->value;
    }
}
