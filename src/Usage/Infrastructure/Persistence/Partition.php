<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Persistence;

use DateTimeImmutable;

final readonly class Partition
{
    public function __construct(
        public string $name,
        public ?DateTimeImmutable $from,
        public ?DateTimeImmutable $to,
        public bool $isDefault,
    ) {}
}
