<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Persistence;

/**
 * One aggregate that does not match the events under it.
 *
 * Three shapes, and they mean different things. `missing` is an aggregate
 * that was never written for events that exist — the shape a crash between
 * the insert and the upsert would leave, if the two were not in one
 * transaction. `extra` is an aggregate with no events under it at all, which
 * means something wrote one outside the writer. `mismatch` is both present
 * and disagreeing, which is the one that would put a wrong number on an
 * invoice.
 */
final readonly class Drift
{
    public const string MISSING = 'missing';

    public const string EXTRA = 'extra';

    public const string MISMATCH = 'mismatch';

    public function __construct(
        public string $kind,
        public string $customerId,
        public string $meterId,
        public string $bucketStart,
        public ?string $storedQuantity,
        public ?string $recomputedQuantity,
        public ?int $storedCount,
        public ?int $recomputedCount,
    ) {}

    public function describe(): string
    {
        return match ($this->kind) {
            self::MISSING => sprintf('no aggregate for %s events', (string) $this->recomputedCount),
            self::EXTRA => sprintf('aggregate of %s with no events under it', (string) $this->storedQuantity),
            default => sprintf(
                'stored %s over %d events, events say %s over %d',
                (string) $this->storedQuantity,
                (int) $this->storedCount,
                (string) $this->recomputedQuantity,
                (int) $this->recomputedCount,
            ),
        };
    }
}
