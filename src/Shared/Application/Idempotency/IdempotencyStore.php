<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Idempotency;

use Metered\Shared\Domain\Idempotency\Claim;
use Metered\Shared\Domain\Idempotency\StoredResponse;

/**
 * Remembers which requests have already been carried out.
 *
 * A client that times out cannot tell whether its request was applied, so
 * retrying is the only sensible behaviour — and without this, a retry creates a
 * second subscription or a second payment.
 */
interface IdempotencyStore
{
    /**
     * Atomically take the key, or report who already has it.
     */
    public function claim(string $scope, string $key, string $fingerprint): Claim;

    /**
     * Record the response, so a later retry replays it instead of executing.
     */
    public function complete(string $scope, string $key, StoredResponse $response): void;

    /**
     * Release a key whose request failed, so the client can genuinely retry.
     */
    public function release(string $scope, string $key): void;

    /**
     * @return int how many expired keys were removed
     */
    public function purgeExpired(): int;
}
