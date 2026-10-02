<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Audit;

/**
 * The first broken link of a chain (every later one fails too, so there is no
 * count). Chain: an organization id, or null for the platform (ADR-0020).
 */
final readonly class VerificationResult
{
    private function __construct(
        public bool $intact,
        public int $entriesChecked,
        public ?int $brokenAtSequence = null,
        public ?string $reason = null,
        public ?string $chain = null,
    ) {}

    public static function intact(int $entriesChecked): self
    {
        return new self(true, $entriesChecked);
    }

    public static function broken(int $entriesChecked, int $sequence, string $reason, ?string $chain): self
    {
        return new self(false, $entriesChecked, $sequence, $reason, $chain);
    }
}
