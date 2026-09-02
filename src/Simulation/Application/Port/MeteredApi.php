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
     * Usage events in batches, several requests at a time.
     *
     * @param  list<list<array<string, string>>>  $batches  at most 100 events each
     * @return int the events the API accepted
     *
     * @throws ApiRefused when a batch is refused for good
     */
    public function ingest(array $batches): int;
}
