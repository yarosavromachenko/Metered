<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Delivery;

/**
 * A status code, or why there is none (timeout, connection refused, guard).
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
        return new self($statusCode, null, false, $durationMs, mb_strcut(self::text($body), 0, self::EXCERPT_LIMIT));
    }

    public static function unreachable(string $error, int $durationMs): self
    {
        return new self(null, self::text($error), false, $durationMs, '');
    }

    /**
     * Nothing was sent.
     */
    public static function refused(string $reason): self
    {
        return new self(null, self::text($reason), true, 0, '');
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

        // Retryable statuses are retried; redirects are never followed (ADR-0011).
        $notNow = $this->statusCode === 408 || $this->statusCode === 429 || $this->statusCode >= 500;

        return $notNow ? Verdict::Retry : Verdict::GiveUp;
    }

    /**
     * The receiver chooses these bytes; PostgreSQL refuses a text value that is not valid UTF-8.
     */
    private static function text(string $bytes): string
    {
        return mb_scrub($bytes, 'UTF-8');
    }
}
