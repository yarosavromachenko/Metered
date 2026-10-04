<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Idempotency;

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
