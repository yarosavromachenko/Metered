<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Persistence;

/**
 * `missing`: events without an aggregate. `extra`: an aggregate without
 * events. `mismatch`: both exist and differ.
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
