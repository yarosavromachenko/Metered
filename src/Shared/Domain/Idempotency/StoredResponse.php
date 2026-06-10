<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Idempotency;

/**
 * The response a completed request produced, kept so that a retry of the same
 * request gets the same answer rather than a second execution.
 */
final readonly class StoredResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}
}
