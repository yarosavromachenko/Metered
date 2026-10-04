<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Idempotency;

use Metered\Shared\Domain\Idempotency\Claim;
use Metered\Shared\Domain\Idempotency\StoredResponse;

/**
 * Idempotency keys of API requests, so a retried request is not applied twice.
 */
interface IdempotencyStore
{
    public function claim(string $scope, string $key, string $fingerprint): Claim;

    public function complete(string $scope, string $key, StoredResponse $response): void;

    /**
     * Frees the key of a failed request so it can be retried.
     */
    public function release(string $scope, string $key): void;

    /**
     * @return int how many expired keys were removed
     */
    public function purgeExpired(): int;
}
