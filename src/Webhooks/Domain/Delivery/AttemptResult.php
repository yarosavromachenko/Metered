<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Delivery;

/**
 * What one attempt came back with: a status code, or the reason there was
 * none — a timeout, a refused connection, an address the guard would not
 * connect to.
 */
final readonly class AttemptResult
{
    public const int EXCERPT_LIMIT = 1024;

    private function __construct(
        public ?int $statusCode,
        public ?string $error,
        public bool $refusedDestination,
        public int $durationMs,
        public string $responseExcerpt,
    ) {}

    public static function responded(int $statusCode, int $durationMs, string $body): self
    {
        return new self($statusCode, null, false, $durationMs, mb_strcut($body, 0, self::EXCERPT_LIMIT));
    }

    public static function unreachable(string $error, int $durationMs): self
    {
        return new self(null, $error, false, $durationMs, '');
    }

    /**
     * The guard refused the address: it resolves somewhere a delivery may not
     * go. Nothing was sent.
     */
    public static function refused(string $reason): self
    {
        return new self(null, $reason, true, 0, '');
    }

    public function verdict(): Verdict
    {
        if ($this->refusedDestination) {
            return Verdict::GiveUp;
        }

        if ($this->statusCode === null) {
            return Verdict::Retry;
        }

        if ($this->statusCode >= 200 && $this->statusCode < 300) {
            return Verdict::Delivered;
        }

        // A receiver saying "not now" is asked again; one saying "never" is
        // believed. A redirect is not followed, and following it next time
        // would not change that (ADR-0011).
        $notNow = $this->statusCode === 408 || $this->statusCode === 429 || $this->statusCode >= 500;

        return $notNow ? Verdict::Retry : Verdict::GiveUp;
    }
}
