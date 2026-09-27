<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

/**
 * The public HTTP API, as one tenant's key sees it.
 *
 * The simulation drives the platform the way a client would (ADR-0016): every
 * meter, plan, customer and event it creates goes through the same routes,
 * validation, idempotency and rate limits as anyone else's.
 */
interface MeteredApi
{
    /**
     * A management write, sent with an Idempotency-Key.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> the decoded answer
     *
     * @throws ApiRefused when the answer is not a success
     */
    public function write(string $path, array $body): array;

    /**
     * A read, with its query string.
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed> the decoded answer
     *
     * @throws ApiRefused when the answer is not a success
     */
    public function read(string $path, array $query = []): array;

    /**
     * Usage events in batches, several requests at a time. Batches are taken
     * from the iterable a few at a time, so a generator never has to hold
     * more than that in memory.
     *
     * @param  iterable<list<array<string, string>>>  $batches  at most 100 events each
     * @return int the events the API accepted
     *
     * @throws ApiRefused when a batch is refused for good
     */
    public function ingest(iterable $batches): int;
}
