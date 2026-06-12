<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Audit;

/**
 * What a verification pass found.
 *
 * It names the first broken link rather than counting them: once one entry is
 * altered, every entry after it fails too, so a count would describe the
 * length of the tail rather than the size of the problem.
 */
final readonly class VerificationResult
{
    private function __construct(
        public bool $intact,
        public int $entriesChecked,
        public ?int $brokenAtSequence = null,
        public ?string $reason = null,
    ) {}

    public static function intact(int $entriesChecked): self
    {
        return new self(true, $entriesChecked);
    }

    public static function broken(int $entriesChecked, int $sequence, string $reason): self
    {
        return new self(false, $entriesChecked, $sequence, $reason);
    }
}
