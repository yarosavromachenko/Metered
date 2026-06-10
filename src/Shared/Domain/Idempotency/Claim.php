<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Idempotency;

/**
 * The outcome of trying to claim a key, and the stored response when there is
 * one to replay.
 */
final readonly class Claim
{
    private function __construct(
        public ClaimStatus $status,
        public ?StoredResponse $response = null,
    ) {}

    public static function claimed(): self
    {
        return new self(ClaimStatus::Claimed);
    }

    public static function replayed(StoredResponse $response): self
    {
        return new self(ClaimStatus::Replayed, $response);
    }

    public static function inProgress(): self
    {
        return new self(ClaimStatus::InProgress);
    }

    public static function fingerprintMismatch(): self
    {
        return new self(ClaimStatus::FingerprintMismatch);
    }
}
