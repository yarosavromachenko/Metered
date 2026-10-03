<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

/**
 * The public HTTP API with one tenant's key; the simulation uses the same
 * routes as any client (ADR-0016).
 */
interface MeteredApi
{
    /**
     * Sent with an Idempotency-Key.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> the decoded answer
     *
     * @throws ApiRefused when the answer is not a success
     */
    public function write(string $path, array $body): array;

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed> the decoded answer
     *
     * @throws ApiRefused when the answer is not a success
     */
    public function read(string $path, array $query = []): array;

    /**
     * Several concurrent requests; batches are pulled lazily from the iterable.
     *
     * @param  iterable<list<array<string, string>>>  $batches  at most 100 events each
     * @return int the events the API accepted
     *
     * @throws ApiRefused when a batch is refused for good
     */
    public function ingest(iterable $batches): int;
}
